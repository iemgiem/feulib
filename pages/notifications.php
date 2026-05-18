<?php
declare(strict_types=1);

/**
 * Notifications — full list for the current user.
 *
 * GET  ?p=notifications              List notifications (read + unread).
 * POST ?p=notifications  action=read_all
 *                                     Mark every unread notification as read.
 *
 * Bulk marking is POST-only on purpose: a GET-time side effect would let
 * browser prefetch / link preview clear the unread badge silently.
 */

$user    = current_user();
$user_id = (int) $user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (($_POST['action'] ?? '') === 'read_all') {
        q(
            'UPDATE notifications SET is_read = 1, read_at = NOW()
              WHERE recipient_account_id = ? AND is_read = 0',
            [$user_id]
        );
        flash_set('success', 'All notifications marked as read.');
    }

    go(url('/index.php?p=notifications'));
}

$unread_total = (int) (q_value(
    'SELECT COUNT(*) FROM notifications
      WHERE recipient_account_id = ? AND is_read = 0',
    [$user_id]
) ?? 0);

$state = table_state('', [
    'sort'     => 'date',
    'dir'      => 'desc',
    'per_page' => 25,
]);

$total = (int) (q_value(
    'SELECT COUNT(*) FROM notifications WHERE recipient_account_id = ?',
    [$user_id]
) ?? 0);

$offset = ($state['page'] - 1) * $state['per_page'];

$notifs = q_all(
    'SELECT id, type, title, body, link_url, is_read, created_at
       FROM notifications
      WHERE recipient_account_id = ?
      ORDER BY created_at DESC
      LIMIT ? OFFSET ?',
    [$user_id, $state['per_page'], $offset]
);

$base = ['p' => 'notifications'];

layout_open('Notifications');

$success = flash_get('success');
if ($success) {
    echo '<div class="alert alert-success" role="status">' . e($success) . '</div>';
}

$mark_all_form = '';
if ($unread_total > 0) {
    $mark_all_form =
        '<form method="POST" action="' . e(url('/index.php?p=notifications')) . '" style="display:inline;">'
        . csrf_field()
        . '<input type="hidden" name="action" value="read_all">'
        . '<button type="submit" class="btn btn-ghost btn-sm">'
        . 'Mark all as read (' . (int) $unread_total . ')'
        . '</button>'
        . '</form>';
}

page_header('Notifications', $mark_all_form);
?>

<section class="card">
  <?php if (!$notifs): ?>
    <div class="empty-state">
      <p class="empty-state-title">No notifications</p>
      <p class="empty-state-body">
        You will receive notifications here when staff propose or approve a match,
        or when your item is ready for pickup.
      </p>
    </div>
  <?php else: ?>
    <ul class="list">
      <?php foreach ($notifs as $n): ?>
        <li class="list-item<?= empty($n['is_read']) ? ' list-item-unread' : '' ?>">
          <div class="list-item-body">
            <p class="list-item-title">
              <?php if (!empty($n['link_url'])): ?>
                <a href="<?= e(url((string) $n['link_url'])) ?>"><?= e((string) $n['title']) ?></a>
              <?php else: ?>
                <?= e((string) $n['title']) ?>
              <?php endif; ?>
              <?php if (empty($n['is_read'])): ?>
                <span class="badge badge-info" style="margin-left: var(--space-2);">new</span>
              <?php endif; ?>
            </p>
            <p class="list-item-text"><?= e((string) $n['body']) ?></p>
            <p class="list-item-meta"><?= e(time_ago((string) $n['created_at'])) ?></p>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
    <?= render_pagination($total, $state, '', $base) ?>
  <?php endif; ?>
</section>

<?php
layout_close();
