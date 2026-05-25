<?php
declare(strict_types=1);

/**
 * Found item confirmation screen — reuses the .confirmation pattern from
 * pages/lost.created.php (Task 8).
 */

$id     = (int) ($_GET['id'] ?? 0);
$report = $id > 0
    ? q_one('SELECT * FROM found_reports WHERE id = ? LIMIT 1', [$id])
    : null;

if ($report === null) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

$location = q_one(
    'SELECT code, description FROM storage_locations WHERE id = ?',
    [(int) $report['storage_location_id']]
);

layout_open('Item logged');
?>

<div class="confirmation">
  <div class="confirmation-icon" aria-hidden="true">
    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
      <polyline points="20 6 9 17 4 12"/>
    </svg>
  </div>

  <h1 class="confirmation-title">Item logged in storage.</h1>
  <p class="confirmation-summary">
    The matching service will compare this item against open lost reports.
    Any candidate matches show up in the Match Validation Queue on your dashboard.
  </p>

  <p class="confirmation-ref-label">Reference number</p>
  <div class="confirmation-ref" aria-label="Reference number">
    <?= e($report['ref_number']) ?>
  </div>

  <div class="confirmation-next">
    <h2>What happens next</h2>
    <ol>
      <li>Place the physical item in <strong><?= e((string) ($location['code'] ?? '—')) ?></strong><?php if (!empty($location['description'])): ?> &mdash; <?= e((string) $location['description']) ?><?php endif; ?>.</li>
      <li>The system proposes matches against open lost reports. Review them in the Match Validation Queue.</li>
      <li>If a match is approved, the user submits a claim with ID proof. Verify in person and release the item.</li>
    </ol>
  </div>

  <div class="confirmation-actions">
    <a href="<?= e(url('/index.php?p=staff.dashboard')) ?>" class="btn btn-primary">Back to dashboard</a>
    <a href="<?= e(url('/index.php?p=found.show&id=' . (int) $report['id'])) ?>" class="btn btn-ghost">View this entry</a>
    <a href="<?= e(url('/index.php?p=found.new')) ?>" class="btn btn-ghost">Log another</a>
  </div>
</div>

<?php
layout_close();
