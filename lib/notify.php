<?php
declare(strict_types=1);

/**
 * Notifications — small helper around the inline INSERTs that the release and
 * match-approval flows used to do by hand.
 *
 * The point of routing through notify() is the admin-configurable rules
 * (Settings → Notification Rules, Task 19): each event type can be switched
 * off, defaulting ON. notify() consults the `notify_<type>` setting and skips
 * the insert when disabled, so callers don't have to.
 *
 * Public surface:
 *   notify_event_types()        Registry of toggleable event types => label.
 *   notify_enabled(string $t)   Is this event type currently on? (default true)
 *   notify(...)                 Insert one notification, subject to the rule.
 */

/**
 * The notification events the system actually emits today. Add a row here when
 * a new notification type is introduced so it shows up in the rules tab.
 *
 * @return array<string,string> event type => human label
 */
function notify_event_types(): array
{
    return [
        'match.approved' => 'Match approved — the owner is told their claim can proceed',
        'claim.released' => 'Item released — the owner is told the handover is complete',
    ];
}

/**
 * Whether a given event type currently sends notifications. Unknown / unset
 * keys default to ON, so notifications work out of the box before an admin ever
 * visits the rules tab.
 */
function notify_enabled(string $type): bool
{
    $val = q_value('SELECT value FROM settings WHERE key_name = ?', ['notify_' . $type]);
    return $val === null ? true : ((string) $val) === '1';
}

/**
 * Insert one in-app notification, subject to the admin notification rules.
 * No-ops silently when the event type is switched off.
 */
function notify(int $recipient_account_id, string $type, string $title, string $body, string $link_url): void
{
    if (!notify_enabled($type)) {
        return;
    }
    q(
        'INSERT INTO notifications
            (recipient_account_id, type, title, body, link_url, created_at)
         VALUES (?, ?, ?, ?, ?, NOW())',
        [$recipient_account_id, $type, $title, $body, $link_url]
    );
}
