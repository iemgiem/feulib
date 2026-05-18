<?php
declare(strict_types=1);

/**
 * Admin — ITS Integration / User Data Sync.
 *
 * GET  ?p=admin.its              Show last-sync info, filter dropdown, and
 *                                the cached its_users table.
 * POST ?p=admin.its              action=sync — pull from the ITS endpoint
 *                                and upsert into its_users.
 *
 * Read-only directory page. The auth source for the application remains
 * the `accounts` table.
 */

// -----------------------------------------------------------------------------
// POST handler — manual sync
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (($_POST['action'] ?? '') === 'sync') {
        $summary = its_sync();

        if ($summary['success']) {
            flash_set('success', sprintf(
                'Sync complete: %d inserted, %d updated, %d deactivated (%d fetched in total).',
                $summary['inserted'],
                $summary['updated'],
                $summary['deactivated'],
                $summary['fetched_total']
            ));
        } else {
            flash_set('error', 'Sync failed: ' . (string) ($summary['error'] ?? 'unknown error'));
        }
    }

    go(url('/index.php?p=admin.its'));
}

// -----------------------------------------------------------------------------
// Page state — filter + pagination
// -----------------------------------------------------------------------------
$state = table_state('', [
    'sort'     => 'name',
    'dir'      => 'asc',
    'per_page' => 25,
]);

$filter_role = strtolower(trim((string) ($_GET['role'] ?? '')));
if (!in_array($filter_role, ['', 'student', 'staff', 'faculty'], true)) {
    $filter_role = '';
}

$where  = [];
$params = [];

if ($state['q'] !== '') {
    $where[]  = '(full_name LIKE ? OR email LIKE ? OR its_id LIKE ?)';
    $like     = '%' . $state['q'] . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($filter_role !== '') {
    $where[]  = 'role = ?';
    $params[] = $filter_role;
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int) (q_value(
    "SELECT COUNT(*) FROM its_users $where_sql",
    $params
) ?? 0);

$offset = ($state['page'] - 1) * $state['per_page'];

$rows = q_all(
    "SELECT id, its_id, full_name, email, role, status, last_synced_at
       FROM its_users
       $where_sql
      ORDER BY full_name ASC
      LIMIT ? OFFSET ?",
    array_merge($params, [$state['per_page'], $offset])
);

$last_sync = its_last_sync_at();

// Counts by role for the summary strip
$count_student = (int) (q_value("SELECT COUNT(*) FROM its_users WHERE role = 'student'") ?? 0);
$count_staff   = (int) (q_value("SELECT COUNT(*) FROM its_users WHERE role IN ('staff','faculty')") ?? 0);
$count_active  = (int) (q_value("SELECT COUNT(*) FROM its_users WHERE status = 'active'") ?? 0);

$base = ['p' => 'admin.its'];
$base_with_state = $base + array_filter([
    'q'    => $state['q'],
    'role' => $filter_role,
], static fn($v) => $v !== '' && $v !== null);

layout_open('ITS Integration');

$success = flash_get('success');
$error   = flash_get('error');
if ($success) {
    echo '<div class="alert alert-success" role="status">' . e($success) . '</div>';
}
if ($error) {
    echo '<div class="alert alert-error" role="alert">' . e($error) . '</div>';
}

$sync_button =
    '<form method="POST" action="' . e(url('/index.php?p=admin.its')) . '" id="its-sync-form" style="display:inline;">'
    . csrf_field()
    . '<input type="hidden" name="action" value="sync">'
    . '<button type="submit" class="btn btn-primary" id="its-sync-btn">'
    . 'Fetch / Sync Users from ITS'
    . '</button>'
    . '</form>';

page_header('ITS Integration', $sync_button);
?>

<section class="card" aria-labelledby="status-title">
  <h2 class="card-title sr-only" id="status-title">Sync status</h2>
  <div class="stat-strip">
    <div class="stat-card">
      <div class="stat-card-value"><?= $count_student ?></div>
      <div class="stat-card-label">Students</div>
    </div>
    <div class="stat-card">
      <div class="stat-card-value"><?= $count_staff ?></div>
      <div class="stat-card-label">Staff / Faculty</div>
    </div>
    <div class="stat-card">
      <div class="stat-card-value"><?= $count_active ?></div>
      <div class="stat-card-label">Active</div>
    </div>
    <div class="stat-card">
      <div class="stat-card-value text-sm">
        <?= $last_sync !== null ? e(time_ago($last_sync)) : '—' ?>
      </div>
      <div class="stat-card-label">Last sync</div>
    </div>
  </div>
</section>

<section class="card" aria-labelledby="filter-title">
  <h2 class="card-title sr-only" id="filter-title">Filters</h2>
  <form method="GET" class="filter-bar" style="flex-wrap: wrap; gap: var(--space-3);">
    <input type="hidden" name="p" value="admin.its">

    <input type="search" name="q" value="<?= e($state['q']) ?>"
           placeholder="Name, email, or ITS ID&hellip;"
           class="form-control" style="flex: 1; min-width: 180px;"
           aria-label="Search ITS users">

    <select name="role" class="form-control" style="width: auto;">
      <option value="">All roles</option>
      <option value="student" <?= $filter_role === 'student' ? 'selected' : '' ?>>Students</option>
      <option value="staff"   <?= $filter_role === 'staff'   ? 'selected' : '' ?>>Staff</option>
      <option value="faculty" <?= $filter_role === 'faculty' ? 'selected' : '' ?>>Faculty</option>
    </select>

    <button type="submit" class="btn btn-ghost btn-sm">Filter</button>
    <a href="<?= e(url('/index.php?p=admin.its')) ?>" class="btn btn-ghost btn-sm">Reset</a>
  </form>
</section>

<section class="card">
  <?php if (!$rows): ?>
    <div class="empty-state">
      <?php if ($last_sync === null): ?>
        <p class="empty-state-title">No ITS users have been synced yet</p>
        <p class="empty-state-body">
          Click <strong>Fetch / Sync Users from ITS</strong> above to pull the
          current student and staff roster from the configured endpoint.
        </p>
      <?php else: ?>
        <p class="empty-state-title">No users match the current filters</p>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>ITS ID</th>
            <th>Name</th>
            <th>Email</th>
            <th class="col-narrow">Role</th>
            <th class="col-narrow">Status</th>
            <th class="col-narrow">Synced</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <tr>
              <td class="text-sm" style="font-family: var(--font-family-mono);">
                <?= e((string) $row['its_id']) ?>
              </td>
              <td><?= e((string) $row['full_name']) ?></td>
              <td class="text-sm text-muted"><?= e((string) ($row['email'] ?? '—')) ?></td>
              <td class="col-narrow text-sm"><?= e(ucfirst((string) $row['role'])) ?></td>
              <td class="col-narrow">
                <?php if ($row['status'] === 'active'): ?>
                  <span class="badge badge-success">active</span>
                <?php else: ?>
                  <span class="badge badge-muted">inactive</span>
                <?php endif; ?>
              </td>
              <td class="col-narrow text-sm text-muted">
                <?= e(time_ago((string) $row['last_synced_at'])) ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= render_pagination($total, $state, '', $base_with_state) ?>
  <?php endif; ?>
</section>

<script>
// Loading indicator — disable the button while the sync runs so the admin
// can see progress and can't double-submit.
(function () {
  var form = document.getElementById('its-sync-form');
  var btn  = document.getElementById('its-sync-btn');
  if (!form || !btn) return;
  form.addEventListener('submit', function () {
    btn.disabled = true;
    btn.textContent = 'Syncing…';
  });
}());
</script>

<?php
layout_close();
