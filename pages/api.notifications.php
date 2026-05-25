<?php
declare(strict_types=1);

/**
 * Notification polling endpoint.
 *
 * GET ?p=api.notifications
 *
 * Returns JSON:
 *   {
 *     "unread": 3,
 *     "items": [
 *       { "id": 42, "title": "...", "body": "...", "link_url": "...", "created_at": "..." }
 *     ]
 *   }
 *
 * The bell in partials/header.php can call this every 60s via JS to update
 * the badge count without a full page reload.
 *
 * Marks nothing as read — display only.
 */

$user    = current_user();
$user_id = (int) $user['id'];

$unread = (int) (q_value(
    'SELECT COUNT(*) FROM notifications WHERE recipient_account_id = ? AND is_read = 0',
    [$user_id]
) ?? 0);

$items = q_all(
    'SELECT id, type, title, body, link_url, is_read, created_at
       FROM notifications
      WHERE recipient_account_id = ?
      ORDER BY created_at DESC
      LIMIT 10',
    [$user_id]
);

// Convert link_url to full URLs
foreach ($items as &$item) {
    if (!empty($item['link_url'])) {
        $item['link_url'] = url((string) $item['link_url']);
    }
    $item['is_read'] = (bool) $item['is_read'];
}
unset($item);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

echo json_encode([
    'unread' => $unread,
    'items'  => $items,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;
