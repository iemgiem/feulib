<?php
declare(strict_types=1);

/**
 * Audit logging — append-only history of state changes.
 *
 *   audit_log('lost_report.create', 'lost_report', $id, ['status' => [null, 'open']]);
 *   audit_log('match.approve',      'match',       $id, ['status' => ['pending', 'approved']]);
 *
 * The actor is read from the current session. For system-generated events
 * (expiry job, cron tasks), call from a CLI script and the actor will be NULL.
 *
 * Task 12 expands this with: read helpers (audit_for_target, audit_recent),
 * diff serializer for full snapshots, and admin view bindings. The base
 * write helper below is enough to instrument every state mutation today.
 */

/**
 * Append one audit row.
 *
 * @param string     $action      Dot-namespaced event, e.g. 'match.approve'.
 * @param string     $target_type Entity table-name singular, e.g. 'lost_report'.
 * @param int        $target_id   Primary key of the affected row.
 * @param array|null $diff        Optional before/after structure; stored as JSON.
 */
function audit_log(string $action, string $target_type, int $target_id, ?array $diff = null): void
{
    // CLI scripts (e.g. db/expire_items.php) run without a session and without
    // a REMOTE_ADDR — both lookups must be tolerant of missing data so the
    // system can audit its own actions.
    $actor_id  = (session_status() === PHP_SESSION_ACTIVE) ? ($_SESSION['user_id'] ?? null) : null;
    $ip        = $_SERVER['REMOTE_ADDR'] ?? null;
    $diff_json = $diff !== null ? json_encode($diff, JSON_UNESCAPED_UNICODE) : null;

    q(
        'INSERT INTO audit_logs
            (actor_account_id, action, target_type, target_id, diff_json, ip_address)
         VALUES (?, ?, ?, ?, ?, ?)',
        [$actor_id, $action, $target_type, $target_id, $diff_json, $ip]
    );
}
