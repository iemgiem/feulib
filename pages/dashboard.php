<?php
declare(strict_types=1);

/**
 * User Dashboard — student/faculty home.
 *
 *   - Welcome strip + "Report a Lost Item" primary CTA
 *   - Stat strip: open reports / possible matches / unread notifications
 *   - Two-column body: My Lost Reports (left) + Notifications (right)
 *   - "How does this work?" expandable help footer
 *
 * Notifications shown here read directly from the table; Task 20's bell will
 * share the same source via the polling endpoint.
 */

$user    = current_user();
$user_id = (int) $user['id'];

// --- Counts for the stat strip --------------------------------------------

$open_count = (int) (q_value(
    'SELECT COUNT(*) FROM lost_reports WHERE reporter_account_id = ? AND status = ?',
    [$user_id, 'open']
) ?? 0);

$matched_count = (int) (q_value(
    'SELECT COUNT(*) FROM lost_reports WHERE reporter_account_id = ? AND status = ?',
    [$user_id, 'matched']
) ?? 0);

$unread_notifs = (int) (q_value(
    'SELECT COUNT(*) FROM notifications WHERE recipient_account_id = ? AND is_read = 0',
    [$user_id]
) ?? 0);

// --- Recent rows -----------------------------------------------------------

$lost_reports = q_all(
    'SELECT id, ref_number, category, description, date_lost, status
       FROM lost_reports
      WHERE reporter_account_id = ?
      ORDER BY created_at DESC
      LIMIT 5',
    [$user_id]
);

$notifications = q_all(
    'SELECT id, type, title, body, link_url, is_read, created_at
       FROM notifications
      WHERE recipient_account_id = ?
      ORDER BY created_at DESC
      LIMIT 3',
    [$user_id]
);

// --- Render ----------------------------------------------------------------

layout_open('Dashboard');

$success = flash_get('success');
if ($success) {
    echo '<div class="alert alert-success" role="status">' . e($success) . '</div>';
}

page_header(
    'Welcome, ' . $user['full_name'],
    '<a class="btn btn-primary btn-lg" href="' . e(url('/index.php?p=lost.new')) . '">Report a Lost Item</a>'
);
?>

<div class="stat-strip">
  <div class="stat-card">
    <div class="stat-card-value"><?= e((string) $open_count) ?></div>
    <div class="stat-card-label">Open Reports</div>
  </div>
  <div class="stat-card">
    <div class="stat-card-value"><?= e((string) $matched_count) ?></div>
    <div class="stat-card-label">Possible Matches</div>
  </div>
  <div class="stat-card">
    <div class="stat-card-value"><?= e((string) $unread_notifs) ?></div>
    <div class="stat-card-label">Unread Notifications</div>
  </div>
</div>

<div class="dash-grid">

  <!-- Left: My Lost Reports -->
  <section class="card" aria-labelledby="my-lost-title">
    <div class="card-header">
      <h2 class="card-title" id="my-lost-title">My Lost Reports</h2>
      <?php if ($lost_reports): ?>
        <a class="card-header-link" href="<?= e(url('/index.php?p=lost')) ?>">View all</a>
      <?php endif; ?>
    </div>

    <?php if (!$lost_reports): ?>
      <div class="empty-state">
        <p class="empty-state-title">No reports yet</p>
        <p class="empty-state-body">When you report a lost item, it will appear here so you can track its status.</p>
        <a class="btn btn-primary" href="<?= e(url('/index.php?p=lost.new')) ?>">Report your first item</a>
      </div>
    <?php else: ?>
      <div class="table-wrap table-wrap-cards">
        <table class="data-table data-table-static">
          <thead>
            <tr>
              <th>Reference</th>
              <th>Item</th>
              <th class="col-narrow">Date lost</th>
              <th class="col-narrow">Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($lost_reports as $r): ?>
              <tr>
                <td data-label="Reference">
                  <a href="<?= e(url('/index.php?p=lost.show&id=' . (int) $r['id'])) ?>">
                    <?= e($r['ref_number']) ?>
                  </a>
                </td>
                <td data-label="Item">
                  <strong><?= e(ucfirst((string) $r['category'])) ?></strong>
                  <span class="text-muted text-sm"> &middot; <?= e(mb_strimwidth((string) $r['description'], 0, 60, '…')) ?></span>
                </td>
                <td data-label="Date lost" class="col-narrow text-sm text-muted">
                  <?= e(date('M j', strtotime((string) $r['date_lost']))) ?>
                </td>
                <td data-label="Status" class="col-narrow"><?= status_badge((string) $r['status']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <!-- Right: Notifications panel -->
  <section class="card" aria-labelledby="notif-title">
    <div class="card-header">
      <h2 class="card-title" id="notif-title">Notifications</h2>
      <?php if ($notifications): ?>
        <a class="card-header-link" href="<?= e(url('/index.php?p=notifications')) ?>">See all</a>
      <?php endif; ?>
    </div>

    <?php if (!$notifications): ?>
      <div class="empty-state">
        <p class="empty-state-title">No notifications</p>
        <p class="empty-state-body">You will see notifications here when staff propose a match or update a claim you submitted.</p>
      </div>
    <?php else: ?>
      <ul class="list">
        <?php foreach ($notifications as $n): ?>
          <li class="list-item<?= !$n['is_read'] ? ' unread' : '' ?>">
            <div class="list-item-body">
              <p class="list-item-title">
                <?php if (!empty($n['link_url'])): ?>
                  <a href="<?= e(url((string) $n['link_url'])) ?>"><?= e((string) $n['title']) ?></a>
                <?php else: ?>
                  <?= e((string) $n['title']) ?>
                <?php endif; ?>
              </p>
              <p class="list-item-text"><?= e(mb_strimwidth((string) $n['body'], 0, 120, '…')) ?></p>
              <p class="list-item-meta"><?= e(time_ago((string) $n['created_at'])) ?></p>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

</div>

<details class="expandable">
  <summary>How does this work?</summary>
  <ol class="text-muted stack-2 mt-4">
    <li><strong>Report what you lost.</strong> Fill out a short form describing the item, where you last saw it, and the date you lost it. Add a photo if you have one.</li>
    <li><strong>Wait for a match.</strong> When library staff log a found item that fits your description, the system proposes a match for staff to review. You will see a notification here.</li>
    <li><strong>Submit a claim.</strong> Once staff approve a match, click through the notification and upload a photo of your school ID, certificate of enrollment, or government ID.</li>
    <li><strong>Pick it up.</strong> Visit the Lost &amp; Found counter (Mon&ndash;Sat, 8am&ndash;5pm) with your reference number. Staff will verify your identity and release the item to you.</li>
  </ol>
</details>

<?php
layout_close();
