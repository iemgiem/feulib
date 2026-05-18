<?php
declare(strict_types=1);

/**
 * Admin reports — generate and view operational summaries.
 *
 * Supported report types:
 *   operational_summary  — totals, statuses, match rate for a date range
 *   match_effectiveness  — score distribution, avg time to match
 *   user_activity        — per-user report counts
 *
 * Reports are computed on demand. Metadata is saved in `reports` table.
 * The full output is shown inline on admin.report.show.
 *
 * POST generates a new report and redirects to its detail page.
 */

$user    = current_user();
$user_id = (int) $user['id'];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $type      = trim($_POST['report_type'] ?? '');
    $date_from = trim($_POST['date_from'] ?? '');
    $date_to   = trim($_POST['date_to'] ?? '');

    $valid_types = ['operational_summary', 'match_effectiveness', 'user_activity'];
    if (!in_array($type, $valid_types, true)) {
        $errors['report_type'] = ['Please select a report type.'];
    }
    if ($date_from === '') {
        $errors['date_from'] = ['Start date is required.'];
    }
    if ($date_to === '') {
        $errors['date_to'] = ['End date is required.'];
    }
    if (!$errors && $date_from > $date_to) {
        $errors['date_from'] = ['Start date must be before end date.'];
    }

    if (!$errors) {
        $params_json = json_encode([
            'date_from' => $date_from,
            'date_to'   => $date_to,
        ], JSON_UNESCAPED_UNICODE);

        q(
            'INSERT INTO reports (generated_by_account_id, report_type, parameters_json, generated_at)
             VALUES (?, ?, ?, NOW())',
            [$user_id, $type, $params_json]
        );
        $report_id = db_last_id();
        go(url('/index.php?p=admin.report.show&id=' . $report_id));
    }
}

// Recent reports list
$recent = q_all(
    'SELECT reports.id, reports.report_type, reports.parameters_json, reports.generated_at,
            accounts.full_name AS generated_by
       FROM reports
       JOIN accounts ON accounts.id = reports.generated_by_account_id
      ORDER BY reports.generated_at DESC
      LIMIT 20'
);

layout_open('Reports');

$flash_success = flash_get('success');
if ($flash_success) {
    echo '<div class="alert alert-success" role="status">' . e($flash_success) . '</div>';
}

if ($errors) {
    echo '<div class="alert alert-error" role="alert"><ul>';
    foreach ($errors as $f) {
        foreach ((array) $f as $err) {
            echo '<li>' . e($err) . '</li>';
        }
    }
    echo '</ul></div>';
}

page_header('Reports');
?>

<div style="display: grid; grid-template-columns: 360px 1fr; gap: var(--card-gap); align-items: start;">

  <!-- Generate form -->
  <section class="card" aria-labelledby="gen-title">
    <h2 class="card-title" id="gen-title">Generate report</h2>

    <form method="POST" class="stack-4">
      <?= csrf_field() ?>

      <div class="form-group">
        <label for="report_type" class="form-label form-label-required">Report type</label>
        <?php if (!empty($errors['report_type'])): ?>
          <div class="form-error"><?= e($errors['report_type'][0]) ?></div>
        <?php endif; ?>
        <select id="report_type" name="report_type"
                class="form-control<?= !empty($errors['report_type']) ? ' is-invalid' : '' ?>">
          <option value="">Choose type&hellip;</option>
          <option value="operational_summary" <?= ($_POST['report_type'] ?? '') === 'operational_summary' ? 'selected' : '' ?>>
            Operational Summary
          </option>
          <option value="match_effectiveness" <?= ($_POST['report_type'] ?? '') === 'match_effectiveness' ? 'selected' : '' ?>>
            Match Effectiveness
          </option>
          <option value="user_activity" <?= ($_POST['report_type'] ?? '') === 'user_activity' ? 'selected' : '' ?>>
            User Activity
          </option>
        </select>
      </div>

      <div class="form-group">
        <label for="date_from" class="form-label form-label-required">From</label>
        <?php if (!empty($errors['date_from'])): ?>
          <div class="form-error"><?= e($errors['date_from'][0]) ?></div>
        <?php endif; ?>
        <input type="date" id="date_from" name="date_from"
               value="<?= e($_POST['date_from'] ?? date('Y-m-01')) ?>"
               class="form-control<?= !empty($errors['date_from']) ? ' is-invalid' : '' ?>">
      </div>

      <div class="form-group">
        <label for="date_to" class="form-label form-label-required">To</label>
        <?php if (!empty($errors['date_to'])): ?>
          <div class="form-error"><?= e($errors['date_to'][0]) ?></div>
        <?php endif; ?>
        <input type="date" id="date_to" name="date_to"
               value="<?= e($_POST['date_to'] ?? date('Y-m-d')) ?>"
               class="form-control<?= !empty($errors['date_to']) ? ' is-invalid' : '' ?>">
      </div>

      <button type="submit" class="btn btn-primary">Generate</button>
    </form>
  </section>

  <!-- Recent reports -->
  <section class="card" aria-labelledby="recent-title">
    <h2 class="card-title" id="recent-title">Recent reports</h2>

    <?php if (!$recent): ?>
      <div class="empty-state">
        <p class="empty-state-title">No reports generated yet</p>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>Type</th>
              <th>Date range</th>
              <th>Generated by</th>
              <th class="col-narrow">When</th>
              <th class="col-actions">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recent as $r): ?>
              <?php $params = json_decode((string) $r['parameters_json'], true) ?? []; ?>
              <tr>
                <td><?= e(ucwords(str_replace('_', ' ', (string) $r['report_type']))) ?></td>
                <td class="text-sm">
                  <?= e((string) ($params['date_from'] ?? '—')) ?>
                  &ndash;
                  <?= e((string) ($params['date_to'] ?? '—')) ?>
                </td>
                <td><?= e((string) $r['generated_by']) ?></td>
                <td class="col-narrow text-sm text-muted"><?= e(time_ago((string) $r['generated_at'])) ?></td>
                <td class="col-actions">
                  <a class="btn btn-ghost btn-sm" href="<?= e(url('/index.php?p=admin.report.show&id=' . (int) $r['id'])) ?>">View</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

</div>

<?php
layout_close();
