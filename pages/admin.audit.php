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

layout_open('Audit Log');

page_header('Audit Log');
?>

<section class="card" aria-labelledby="filter-title">
  <h2 class="card-title sr-only" id="filter-title">Filters</h2>
  <form method="GET" class="filter-bar" style="flex-wrap: wrap; gap: var(--space-3);">
    <input type="hidden" name="p" value="admin.audit">

    <input type="search" name="q" value="<?= e($state['q']) ?>"
           placeholder="Actor name, action, target&hellip;"
           class="form-control" style="flex: 1; min-width: 180px;"
           aria-label="Search audit log">

    <select name="action" class="form-control" style="width: auto;">
      <option value="">All actions</option>
      <?php foreach ($action_prefixes as $row): ?>
        <option value="<?= e((string) $row['prefix']) ?>"
          <?= $filter_action === $row['prefix'] ? 'selected' : '' ?>>
          <?= e((string) $row['prefix']) ?>.*
        </option>
      <?php endforeach; ?>
    </select>

    <div style="display: flex; align-items: center; gap: var(--space-2);">
      <label for="from" class="form-label" style="margin: 0; white-space: nowrap;">From</label>
      <input type="date" id="from" name="from" value="<?= e($filter_from) ?>"
             class="form-control" style="width: auto;">
    </div>

    <div style="display: flex; align-items: center; gap: var(--space-2);">
      <label for="to" class="form-label" style="margin: 0; white-space: nowrap;">To</label>
      <input type="date" id="to" name="to" value="<?= e($filter_to) ?>"
             class="form-control" style="width: auto;">
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
    <div class="table-wrap">
      <table class="data-table">
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
          <?php foreach ($entries as $e): ?>
            <tr>
              <td class="col-narrow text-sm text-muted"><?= (int) $e['id'] ?></td>
              <td>
                <span class="text-sm" style="font-family: var(--font-family-mono);">
                  <?= htmlspecialchars((string) $e['action'], ENT_QUOTES, 'UTF-8') ?>
                </span>
              </td>
              <td>
                <span class="text-sm">
                  <?= htmlspecialchars((string) $e['target_type'], ENT_QUOTES, 'UTF-8') ?>
                  #<?= (int) $e['target_id'] ?>
                </span>
                <?php if (!empty($e['diff_json'])): ?>
                  <details class="text-sm text-muted" style="margin-top: var(--space-1);">
                    <summary>diff</summary>
                    <pre style="white-space: pre-wrap; font-size: 0.75em; max-width: 320px;"><?= htmlspecialchars((string) $e['diff_json'], ENT_QUOTES, 'UTF-8') ?></pre>
                  </details>
                <?php endif; ?>
              </td>
              <td>
                <?php if (!empty($e['actor_name'])): ?>
                  <?= htmlspecialchars((string) $e['actor_name'], ENT_QUOTES, 'UTF-8') ?>
                  <div class="text-sm text-muted"><?= htmlspecialchars((string) $e['actor_email'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php else: ?>
                  <span class="text-muted">system</span>
                <?php endif; ?>
              </td>
              <td class="col-narrow text-sm text-muted"><?= htmlspecialchars((string) ($e['ip_address'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
              <td class="col-narrow text-sm text-muted"><?= htmlspecialchars(time_ago((string) $e['created_at']), ENT_QUOTES, 'UTF-8') ?></td>
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
