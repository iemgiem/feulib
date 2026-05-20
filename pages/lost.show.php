<?php
declare(strict_types=1);

/**
 * Lost report detail.
 *
 * Visible to:
 *   - the reporter (always)
 *   - staff + admin (always — they're the matching workforce)
 *
 * Edit-while-OPEN affordance:
 *   - The Edit button appears only when status='open' AND the viewer owns the row.
 *   - The lost.new page enforces the same rule server-side.
 */

$user    = current_user();
$user_id = (int) $user['id'];
$role    = (string) $user['role'];
$id      = (int) ($_GET['id'] ?? 0);

if ($id <= 0) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

$report = q_one('SELECT * FROM lost_reports WHERE id = ? LIMIT 1', [$id]);
if ($report === null) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

// Permission: reporter, staff, or admin.
$is_owner = (int) $report['reporter_account_id'] === $user_id;
if (!$is_owner && $role !== 'staff' && $role !== 'admin') {
    http_response_code(403);
    require __DIR__ . '/403.php';
    exit;
}

// Reporter info (helpful for staff)
$reporter = q_one('SELECT id, full_name, email FROM accounts WHERE id = ?', [(int) $report['reporter_account_id']]);

// Photo attachments
$photos = q_all(
    "SELECT * FROM attachments WHERE attachable_type = 'lost_report' AND attachable_id = ? ORDER BY id",
    [$id]
);

$category_label = category_label((string) $report['category']);

$is_open  = $report['status'] === 'open';
$can_edit = $is_owner && $is_open;

$actions = '';
if ($can_edit) {
    $actions = '<a class="btn btn-primary" href="' . e(url('/index.php?p=lost.new&id=' . $id)) . '">Edit report</a>';
}

layout_open('Report ' . $report['ref_number']);

$success = flash_get('success');
if ($success) {
    echo '<div class="alert alert-success" role="status">' . e($success) . '</div>';
}

breadcrumb([
    ['Dashboard', url('/index.php?p=dashboard')],
    ['My Lost Reports', url('/index.php?p=lost')],
    [$report['ref_number']],
]);

page_header($report['ref_number'], $actions);
?>

<div style="display: grid; grid-template-columns: 1fr 280px; gap: var(--card-gap); align-items: start;">

  <section class="card" aria-labelledby="detail-title">
    <div class="card-header">
      <h2 class="card-title" id="detail-title">Item details</h2>
      <?= status_badge((string) $report['status']) ?>
    </div>

    <?php if ($photos): ?>
      <?php $first_photo = $photos[0]; ?>
      <div class="detail-photo">
        <img src="<?= e(upload_url($first_photo)) ?>"
             alt="<?= e($category_label . ', ' . (string) $report['color']) ?>">
      </div>
    <?php endif; ?>

    <dl class="detail-grid">
      <dt>Category</dt>
      <dd><?= e($category_label) ?></dd>

      <dt>Color</dt>
      <dd><?= e((string) $report['color']) ?></dd>

      <?php if (!empty($report['brand'])): ?>
        <dt>Brand / marks</dt>
        <dd><?= e((string) $report['brand']) ?></dd>
      <?php endif; ?>

      <dt>Description</dt>
      <dd style="white-space: pre-wrap;"><?= e((string) $report['description']) ?></dd>

      <dt>Last seen</dt>
      <dd><?= e((string) $report['last_seen_location']) ?></dd>

      <dt>Date lost</dt>
      <dd><?= e(date('F j, Y', strtotime((string) $report['date_lost']))) ?></dd>
    </dl>
  </section>

  <aside class="card">
    <h2 class="card-title">Status</h2>
    <p class="card-subtitle" style="margin-bottom: var(--space-3);">
      <?php if ($is_open): ?>
        Waiting for a matching found item.
      <?php elseif ($report['status'] === 'matched'): ?>
        A staff member proposed a match. Check your notifications.
      <?php elseif ($report['status'] === 'claimed'): ?>
        A claim is in progress. Bring your reference number to the counter.
      <?php elseif ($report['status'] === 'released'): ?>
        The item has been released to you.
      <?php elseif ($report['status'] === 'expired'): ?>
        This report passed the holding period without being claimed.
      <?php endif; ?>
    </p>

    <dl class="detail-grid" style="grid-template-columns: 1fr; row-gap: var(--space-2);">
      <dt>Reference</dt>
      <dd style="font-family: var(--font-family-mono);"><?= e($report['ref_number']) ?></dd>

      <dt>Reported</dt>
      <dd><?= e(time_ago((string) $report['created_at'])) ?></dd>

      <?php if ($role === 'staff' || $role === 'admin'): ?>
        <dt>Reporter</dt>
        <dd>
          <?= e((string) ($reporter['full_name'] ?? '—')) ?><br>
          <span class="text-sm text-muted"><?= e((string) ($reporter['email'] ?? '')) ?></span>
        </dd>
      <?php endif; ?>
    </dl>

    <?php if ($can_edit): ?>
      <p class="text-muted text-sm mt-4">
        You can still edit this report while it is OPEN. Once it is matched
        or claimed, edits are disabled to preserve the chain of custody.
      </p>
    <?php endif; ?>
  </aside>

</div>

<?php
layout_close();
