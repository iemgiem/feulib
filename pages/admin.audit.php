<?php
declare(strict_types=1);

/**
 * Admin — Audit log viewer.
 * Filterable by actor, action prefix, target type, and date range.
 * Read-only; append-only source table.
 */

$state = table_state('', [
    'sort'     => 'date',
    'dir'      => 'desc',
    'per_page' => 50,
]);

// Extra filters specific to the audit log
$filter_action = trim($_GET['action'] ?? '');
$filter_from   = trim($_GET['from'] ?? '');
$filter_to     = trim($_GET['to'] ?? '');

$where  = [];
$params = [];

if ($state['q'] !== '') {
    $where[]  = '(accounts.full_name LIKE ? OR audit_logs.action LIKE ? OR audit_logs.target_type LIKE ?)';
    $like     = '%' . $state['q'] . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($filter_action !== '') {
    $where[]  = 'audit_logs.action LIKE ?';
    $params[] = $filter_action . '%';
}
if ($filter_from !== '') {
    $where[]  = 'audit_logs.created_at >= ?';
    $params[] = $filter_from . ' 00:00:00';
}
if ($filter_to !== '') {
    $where[]  = 'audit_logs.created_at <= ?';
    $params[] = $filter_to . ' 23:59:59';
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// -----------------------------------------------------------------------------
// CSV export branch — fires before any HTML output. Exports the full filtered
// set (no pagination), reusing the WHERE built above.
// -----------------------------------------------------------------------------
if (($_GET['format'] ?? '') === 'csv') {
    $export_rows = q_all(
        'SELECT audit_logs.id,
                audit_logs.action,
                audit_logs.target_type,
                audit_logs.target_id,
                audit_logs.diff_json,
                audit_logs.ip_address,
                audit_logs.created_at,
                accounts.full_name AS actor_name,
                accounts.email     AS actor_email
           FROM audit_logs
           LEFT JOIN accounts ON accounts.id = audit_logs.actor_account_id
          ' . $where_sql . '
          ORDER BY audit_logs.created_at DESC, audit_logs.id DESC',
        $params
    );

    csv_send('lfms-audit-log-' . date('Y-m-d') . '.csv', function ($h) use ($export_rows) {
        csv_row($h, ['FEU Library — Lost & Found Management System']);
        csv_row($h, ['Audit log export']);
        csv_row($h, ['Generated at', date('Y-m-d H:i:s')]);
        csv_row($h, []);
        csv_row($h, ['ID', 'Action', 'Target type', 'Target ID', 'Actor', 'Actor email', 'IP', 'Timestamp', 'Changes (JSON)']);
        foreach ($export_rows as $r) {
            csv_row($h, [
                (int) $r['id'],
                (string) $r['action'],
                (string) $r['target_type'],
                (int) $r['target_id'],
                (string) ($r['actor_name'] ?? 'system'),
                (string) ($r['actor_email'] ?? ''),
                (string) ($r['ip_address'] ?? ''),
                (string) $r['created_at'],
                (string) ($r['diff_json'] ?? ''),
            ]);
        }
    });
}

$total = (int) (q_value(
    'SELECT COUNT(*) FROM audit_logs
       LEFT JOIN accounts ON accounts.id = audit_logs.actor_account_id
      ' . $where_sql,
    $params
) ?? 0);

$offset = ($state['page'] - 1) * $state['per_page'];

$entries = q_all(
    'SELECT audit_logs.id,
            audit_logs.action,
            audit_logs.target_type,
            audit_logs.target_id,
            audit_logs.diff_json,
            audit_logs.ip_address,
            audit_logs.created_at,
            accounts.full_name  AS actor_name,
            accounts.email      AS actor_email
       FROM audit_logs
       LEFT JOIN accounts ON accounts.id = audit_logs.actor_account_id
      ' . $where_sql . '
      ORDER BY audit_logs.created_at DESC, audit_logs.id DESC
      LIMIT ? OFFSET ?',
    array_merge($params, [$state['per_page'], $offset])
);

// Distinct action prefixes for filter dropdown
$action_prefixes = q_all(
    "SELECT DISTINCT SUBSTRING_INDEX(action, '.', 1) AS prefix FROM audit_logs ORDER BY 1"
);

$base = ['p' => 'admin.audit'];

$base_with_state = $base + array_filter([
    'q'      => $state['q'],
    'action' => $filter_action,
    'from'   => $filter_from,
    'to'     => $filter_to,
    'per_page' => $state['per_page'] !== 50 ? (string) $state['per_page'] : '',
], static fn($v) => $v !== '' && $v !== null);

$export_url = url('/index.php?' . http_build_query($base_with_state + ['format' => 'csv']));

layout_open('Audit Log');

page_header('Audit Log', '<a class="btn btn-ghost btn-sm" href="' . e($export_url) . '">Export CSV</a>');
?>

<section class="card" aria-labelledby="filter-title">
  <h2 class="card-title sr-only" id="filter-title">Filters</h2>
  <form method="GET" class="filter-bar filter-bar-wrap">
    <input type="hidden" name="p" value="admin.audit">

    <input type="search" name="q" value="<?= e($state['q']) ?>"
           placeholder="Actor name, action, target&hellip;"
           class="form-control field-flex"
           aria-label="Search audit log">

    <select name="action" class="form-control field-auto">
      <option value="">All actions</option>
      <?php foreach ($action_prefixes as $row): ?>
        <option value="<?= e((string) $row['prefix']) ?>"
          <?= $filter_action === $row['prefix'] ? 'selected' : '' ?>>
          <?= e((string) $row['prefix']) ?>.*
        </option>
      <?php endforeach; ?>
    </select>

    <div class="filter-date-range">
      <label for="from" class="form-label">From</label>
      <input type="date" id="from" name="from" value="<?= e($filter_from) ?>"
             class="form-control field-auto">
    </div>

    <div class="filter-date-range">
      <label for="to" class="form-label">To</label>
      <input type="date" id="to" name="to" value="<?= e($filter_to) ?>"
             class="form-control field-auto">
    </div>

    <button type="submit" class="btn btn-ghost btn-sm">Filter</button>
    <a href="<?= e(url('/index.php?p=admin.audit')) ?>" class="btn btn-ghost btn-sm">Reset</a>
  </form>
</section>

<section class="card">
  <?php if (!$entries): ?>
    <div class="empty-state">
      <p class="empty-state-title">No log entries match the current filters</p>
    </div>
  <?php else: ?>
    <div class="table-wrap table-wrap-cards">
      <table class="data-table data-table-static">
        <thead>
          <tr>
            <th class="col-narrow">#</th>
            <th>Action</th>
            <th>Target</th>
            <th>Actor</th>
            <th class="col-narrow">IP</th>
            <th class="col-narrow">When</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($entries as $entry): ?>
            <tr>
              <td data-label="#" class="col-narrow text-sm text-muted"><?= (int) $entry['id'] ?></td>
              <td data-label="Action">
                <span class="text-sm" style="font-family: var(--font-family-mono);">
                  <?= e((string) $entry['action']) ?>
                </span>
              </td>
              <td data-label="Target">
                <span class="text-sm">
                  <?= e((string) $entry['target_type']) ?>
                  #<?= (int) $entry['target_id'] ?>
                </span>
                <?php if (!empty($entry['diff_json'])): ?>
                  <details class="text-sm text-muted" style="margin-top: var(--space-1);">
                    <summary>diff</summary>
                    <pre style="white-space: pre-wrap; font-size: 0.75em; max-width: 320px;"><?= e((string) $entry['diff_json']) ?></pre>
                  </details>
                <?php endif; ?>
              </td>
              <td data-label="Actor">
                <?php if (!empty($entry['actor_name'])): ?>
                  <?= e((string) $entry['actor_name']) ?>
                  <div class="text-sm text-muted"><?= e((string) $entry['actor_email']) ?></div>
                <?php else: ?>
                  <span class="text-muted">system</span>
                <?php endif; ?>
              </td>
              <td data-label="IP" class="col-narrow text-sm text-muted"><?= e((string) ($entry['ip_address'] ?? '—')) ?></td>
              <td data-label="When" class="col-narrow text-sm text-muted"><?= e(time_ago((string) $entry['created_at'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= render_pagination($total, $state, '', $base_with_state) ?>
  <?php endif; ?>
</section>

<?php
layout_close();
