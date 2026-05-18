<?php
declare(strict_types=1);

/**
 * Notifications — full list for the current user.
 * Marks all unread as read on page load.
 * POST ?mark=N marks a single notification as read (used by JS if needed).
 */

$user    = current_user();
$user_id = (int) $user['id'];

// Mark all unread as read on arrival (bulk).
q(
    'UPDATE notifications SET is_read = 1, read_at = NOW()
      WHERE recipient_account_id = ? AND is_read = 0',
    [$user_id]
);

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

page_header('Notifications');
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
        <li class="list-item">
          <div class="list-item-body">
            <p class="list-item-title">
              <?php if (!empty($n['link_url'])): ?>
                <a href="<?= e(url((string) $n['link_url'])) ?>"><?= e((string) $n['title']) ?></a>
              <?php else: ?>
                <?= e((string) $n['title']) ?>
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
