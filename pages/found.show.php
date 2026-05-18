<?php
declare(strict_types=1);

/**
 * Found item detail — staff/admin view.
 *
 * Shows: full item metadata, photo, storage location, finder, and any
 * related matches (with score + status + a "Review" link to /p=match.show).
 *
 * Edit-while-OPEN: any staff/admin can edit an OPEN found item. Once the
 * item progresses (matched / claimed / released / expired / donated), edit
 * is disabled to preserve the chain of custody.
 */

$user = current_user();
$id   = (int) ($_GET['id'] ?? 0);

if ($id <= 0) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

$report = q_one('SELECT * FROM found_reports WHERE id = ? LIMIT 1', [$id]);
if ($report === null) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

$location = q_one(
    'SELECT code, description FROM storage_locations WHERE id = ?',
    [(int) $report['storage_location_id']]
);

$finder = q_one(
    'SELECT id, full_name, email FROM accounts WHERE id = ?',
    [(int) $report['finder_account_id']]
);

$photos = q_all(
    "SELECT * FROM attachments
      WHERE attachable_type = 'found_report' AND attachable_id = ?
      ORDER BY id",
    [$id]
);

$related_matches = q_all(
    'SELECT matches.id, matches.score, matches.status, matches.created_at,
            lost_reports.id           AS lost_id,
            lost_reports.ref_number   AS lost_ref,
            lost_reports.category     AS lost_category,
            lost_reports.color        AS lost_color
       FROM matches
       JOIN lost_reports ON lost_reports.id = matches.lost_report_id
      WHERE matches.found_report_id = ?
      ORDER BY matches.score DESC, matches.created_at DESC',
    [$id]
);

$is_open  = $report['status'] === 'open';
$actions  = '';
if ($is_open) {
    $actions = '<a class="btn btn-primary" href="' . e(url('/index.php?p=found.new&id=' . $id)) . '">Edit entry</a>';
}

layout_open('Found ' . $report['ref_number']);

$success = flash_get('success');
if ($success) {
    echo '<div class="alert alert-success" role="status">' . e($success) . '</div>';
}

breadcrumb([
    ['Dashboard',   url('/index.php?p=staff.dashboard')],
    ['Found Items', url('/index.php?p=found')],
    [$report['ref_number']],
]);

page_header($report['ref_number'], $actions);
?>

<div style="display: grid; grid-template-columns: 1fr 280px; gap: var(--card-gap); align-items: start;">

  <div>
    <section class="card" aria-labelledby="detail-title">
      <div class="card-header">
        <h2 class="card-title" id="detail-title">Item details</h2>
        <?= status_badge((string) $report['status']) ?>
      </div>

      <?php if ($photos): ?>
        <?php $first_photo = $photos[0]; ?>
        <div class="detail-photo">
          <img src="<?= e(upload_url($first_photo)) ?>" alt="Photo of the found item">
        </div>
      <?php endif; ?>

      <dl class="detail-grid">
        <dt>Category</dt>
        <dd><?= e(category_label((string) $report['category'])) ?></dd>

        <dt>Color</dt>
        <dd><?= e((string) $report['color']) ?></dd>

        <?php if (!empty($report['brand'])): ?>
          <dt>Brand / marks</dt>
          <dd><?= e((string) $report['brand']) ?></dd>
        <?php endif; ?>

        <dt>Description</dt>
        <dd style="white-space: pre-wrap;"><?= e((string) $report['description']) ?></dd>

        <dt>Storage location</dt>
        <dd>
          <strong><?= e((string) ($location['code'] ?? '—')) ?></strong>
          <?php if (!empty($location['description'])): ?>
            <div class="text-sm text-muted"><?= e((string) $location['description']) ?></div>
          <?php endif; ?>
        </dd>

        <dt>Date found</dt>
        <dd><?= e(date('F j, Y', strtotime((string) $report['date_found']))) ?></dd>
      </dl>
    </section>

    <?php if ($related_matches): ?>
      <section class="card" aria-labelledby="matches-title">
        <div class="card-header">
          <h2 class="card-title" id="matches-title">Related matches</h2>
          <span class="text-muted text-sm"><?= count($related_matches) ?> total</span>
        </div>
        <div class="table-wrap">
          <table class="data-table">
            <thead>
              <tr>
                <th class="col-narrow">Score</th>
                <th>Lost report</th>
                <th class="col-narrow">Status</th>
                <th class="col-narrow">Proposed</th>
                <th class="col-actions">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($related_matches as $m): ?>
                <tr>
                  <td class="col-narrow"><?= score_chip((int) $m['score']) ?></td>
                  <td>
                    <a href="<?= e(url('/index.php?p=lost.show&id=' . (int) $m['lost_id'])) ?>">
                      <?= e((string) $m['lost_ref']) ?>
                    </a>
                    <div class="text-sm text-muted">
                      <?= e(category_label((string) $m['lost_category'])) ?> &middot; <?= e((string) $m['lost_color']) ?>
                    </div>
                  </td>
                  <td class="col-narrow"><?= status_badge((string) $m['status']) ?></td>
                  <td class="col-narrow text-sm text-muted"><?= e(time_ago((string) $m['created_at'])) ?></td>
                  <td class="col-actions">
                    <a class="btn btn-ghost btn-sm" href="<?= e(url('/index.php?p=match.show&id=' . (int) $m['id'])) ?>">Review</a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
    <?php endif; ?>
  </div>

  <aside class="card">
    <h2 class="card-title">Status</h2>
    <p class="card-subtitle" style="margin-bottom: var(--space-3);">
      <?php if ($is_open): ?>
        In storage. Waiting for a matching lost report.
      <?php elseif ($report['status'] === 'matched'): ?>
        Awaiting claim from the matched user.
      <?php elseif ($report['status'] === 'claimed'): ?>
        A claim is in progress.
      <?php elseif ($report['status'] === 'released'): ?>
        Released to the rightful owner.
      <?php elseif ($report['status'] === 'expired'): ?>
        Past the holding period without being claimed.
      <?php elseif ($report['status'] === 'donated'): ?>
        Donated to a partner beneficiary.
      <?php endif; ?>
    </p>

    <dl class="detail-grid" style="grid-template-columns: 1fr; row-gap: var(--space-2);">
      <dt>Reference</dt>
      <dd style="font-family: var(--font-family-mono);"><?= e($report['ref_number']) ?></dd>

      <dt>Logged</dt>
      <dd><?= e(time_ago((string) $report['created_at'])) ?></dd>

      <dt>Logged by</dt>
      <dd>
        <?= e((string) ($finder['full_name'] ?? '—')) ?><br>
        <span class="text-sm text-muted"><?= e((string) ($finder['email'] ?? '')) ?></span>
      </dd>
    </dl>

    <?php if ($is_open): ?>
      <p class="text-muted text-sm mt-4">
        Edits are allowed while the entry is OPEN. Once a match is approved or a
        claim is in progress, edits are disabled to preserve the chain of custody.
      </p>
    <?php endif; ?>
  </aside>

</div>

<?php
layout_close();
