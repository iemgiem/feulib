<?php
declare(strict_types=1);

/**
 * Lost report confirmation screen.
 * Establishes the confirmation pattern reused by Found and Claim.
 */

$user    = current_user();
$user_id = (int) $user['id'];
$id      = (int) ($_GET['id'] ?? 0);

$report = $id > 0
    ? q_one('SELECT * FROM lost_reports WHERE id = ? AND reporter_account_id = ? LIMIT 1', [$id, $user_id])
    : null;

if ($report === null) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

layout_open('Report submitted');
?>

<div class="confirmation">
  <div class="confirmation-icon" aria-hidden="true">
    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
      <polyline points="20 6 9 17 4 12"/>
    </svg>
  </div>

  <h1 class="confirmation-title">Your report is on file.</h1>
  <p class="confirmation-summary">
    Library staff will be watching for a match. You will see a notification on
    your dashboard if a found item fits your description.
  </p>

  <p class="confirmation-ref-label">Reference number</p>
  <div class="confirmation-ref" aria-label="Reference number">
    <?= e($report['ref_number']) ?>
  </div>

  <div class="confirmation-next">
    <h2>What happens next</h2>
    <ol>
      <li>Staff log every found item turned in at the library. The system compares each one against your report.</li>
      <li>If a match is proposed and a staff member approves it, a notification appears on your dashboard.</li>
      <li>Click the notification to submit a claim. You will upload a photo of your ID, then visit the counter to pick up your item.</li>
    </ol>
  </div>

  <div class="confirmation-actions">
    <a href="<?= e(url('/index.php?p=dashboard')) ?>" class="btn btn-primary">Back to dashboard</a>
    <a href="<?= e(url('/index.php?p=lost.show&id=' . (int) $report['id'])) ?>" class="btn btn-ghost">View this report</a>
  </div>
</div>

<?php
layout_close();
