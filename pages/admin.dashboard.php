<?php
declare(strict_types=1);

/**
 * Admin dashboard — 6-card stat strip, Recent Activity audit feed,
 * and Quick Links to reports, settings, and audit log.
 */

$user = current_user();

// --- Stat strip ---
$total_lost    = (int) (q_value("SELECT COUNT(*) FROM lost_reports") ?? 0);
$total_found   = (int) (q_value("SELECT COUNT(*) FROM found_reports") ?? 0);
$total_released = (int) (q_value("SELECT COUNT(*) FROM claim_tickets WHERE status = 'released'") ?? 0);
$total_open    = (int) (q_value("SELECT COUNT(*) FROM found_reports WHERE status IN ('open','matched')") ?? 0);

$holding_period = (int) (q_value("SELECT value FROM settings WHERE key_name = 'holding_period_days'") ?? 365);
if ($holding_period < 1) $holding_period = 365;
$warn_threshold = max(1, $holding_period - 7);
$expiring_soon  = (int) (q_value(
    "SELECT COUNT(*) FROM found_reports
      WHERE status IN ('open','matched')
        AND date_found <= (CURDATE() - INTERVAL ? DAY)",
    [$warn_threshold]
) ?? 0);
$pending_matches = (int) (q_value("SELECT COUNT(*) FROM matches WHERE status IN ('pending','needs_info')") ?? 0);

// Match rate: approved matches / total lost reports
$approved_matches = (int) (q_value("SELECT COUNT(*) FROM matches WHERE status = 'approved'") ?? 0);
$match_rate = $total_lost > 0 ? round($approved_matches / $total_lost * 100, 1) : 0;

// --- Recent audit activity ---
$recent_audit = q_all(
    'SELECT audit_logs.action, audit_logs.target_type, audit_logs.target_id,
            audit_logs.created_at, accounts.full_name AS actor_name
       FROM audit_logs
       LEFT JOIN accounts ON accounts.id = audit_logs.actor_account_id
      ORDER BY audit_logs.created_at DESC
      LIMIT 10'
);

layout_open('Admin Dashboard');

$success = flash_get('success');
if ($success) {
    echo '<div class="alert alert-success" role="status">' . e($success) . '</div>';
}

page_header('Administration', '<a class="btn btn-ghost" href="' . e(url('/index.php?p=admin.reports')) . '">Reports</a>');
?>

<div class="stat-strip">
  <div class="stat-card">
    <div class="stat-card-value"><?= $total_lost ?></div>
    <div class="stat-card-label">Total Lost Reports</div>
  </div>
  <div class="stat-card">
    <div class="stat-card-value"><?= $total_found ?></div>
    <div class="stat-card-label">Found Items Logged</div>
  </div>
  <div class="stat-card">
    <div class="stat-card-value"><?= $match_rate ?>%</div>
    <div class="stat-card-label">Match Rate</div>
  </div>
  <div class="stat-card">
    <div class="stat-card-value"><?= $total_released ?></div>
    <div class="stat-card-label">Items Released</div>
  </div>
  <div class="stat-card">
    <div class="stat-card-value"><?= $total_open ?></div>
    <div class="stat-card-label">In Storage</div>
  </div>
  <div class="stat-card">
    <div class="stat-card-value"><?= $expiring_soon ?></div>
    <div class="stat-card-label">Expiring Soon</div>
  </div>
</div>

<div style="display: grid; grid-template-columns: 1fr 280px; gap: var(--card-gap); align-items: start;">

  <!-- Recent Activity -->
  <section class="card" aria-labelledby="activity-title">
    <div class="card-header">
      <h2 class="card-title" id="activity-title">Recent Activity</h2>
      <a class="card-header-link" href="<?= e(url('/index.php?p=admin.audit')) ?>">Full audit log</a>
    </div>

    <?php if (!$recent_audit): ?>
      <div class="empty-state">
        <p class="empty-state-title">No activity yet</p>
      </div>
    <?php else: ?>
      <ul class="list">
        <?php foreach ($recent_audit as $entry): ?>
          <li class="list-item">
            <div class="list-item-body">
              <p class="list-item-title">
                <span style="font-family: var(--font-family-mono); font-size: var(--font-size-sm);">
                  <?= e((string) $entry['action']) ?>
                </span>
              </p>
              <p class="list-item-text">
                <?= e((string) $entry['target_type']) ?> #<?= (int) $entry['target_id'] ?>
                <?php if (!empty($entry['actor_name'])): ?>
                  &mdash; by <?= e((string) $entry['actor_name']) ?>
                <?php endif; ?>
              </p>
              <p class="list-item-meta"><?= e(time_ago((string) $entry['created_at'])) ?></p>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <!-- Quick links -->
  <aside>
    <section class="card" aria-labelledby="links-title">
      <h2 class="card-title" id="links-title">Quick Links</h2>
      <nav class="stack-2">
        <a class="btn btn-ghost" style="justify-content: flex-start; width: 100%;"
           href="<?= e(url('/index.php?p=admin.reports')) ?>">
          Reports
        </a>
        <a class="btn btn-ghost" style="justify-content: flex-start; width: 100%;"
           href="<?= e(url('/index.php?p=admin.audit')) ?>">
          Audit Log
        </a>
        <a class="btn btn-ghost" style="justify-content: flex-start; width: 100%;"
           href="<?= e(url('/index.php?p=admin.its')) ?>">
          ITS Integration
        </a>
        <a class="btn btn-ghost" style="justify-content: flex-start; width: 100%;"
           href="<?= e(url('/index.php?p=admin.settings')) ?>">
          Settings
        </a>
        <a class="btn btn-ghost" style="justify-content: flex-start; width: 100%;"
           href="<?= e(url('/index.php?p=matches')) ?>">
          All Matches
        </a>
        <a class="btn btn-ghost" style="justify-content: flex-start; width: 100%;"
           href="<?= e(url('/index.php?p=staff.claims')) ?>">
          All Claims
        </a>
      </nav>
    </section>

    <?php if ($pending_matches > 0): ?>
      <section class="card mt-4" role="alert">
        <p class="text-muted text-sm">
          <strong><?= $pending_matches ?></strong> match<?= $pending_matches !== 1 ? 'es' : '' ?>
          pending staff review.
        </p>
        <a class="btn btn-primary btn-sm mt-3" href="<?= e(url('/index.php?p=matches&status=pending')) ?>">
          Review now
        </a>
      </section>
    <?php endif; ?>
  </aside>

</div>

<?php
layout_close();
