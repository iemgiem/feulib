<?php
declare(strict_types=1);

/**
 * Found Items — list view for staff + admin.
 *
 *   ?p=found                           all currently active found items
 *   ?p=found&status=open               filter by status
 *   ?p=found&q=jansport                keyword search
 *   ?p=found&storage=2                 filter by storage location id
 *
 * Pagination uses the same table_state() + render_pagination() machinery as
 * the staff dashboard (Task 9). Default per_page=25, sort by date_found DESC.
 */

$user = current_user();

$state = table_state('', [
    'sort'     => 'created',
    'dir'      => 'desc',
    'per_page' => 25,
]);

$valid_status = ['open', 'matched', 'claimed', 'released', 'expired', 'donated'];
$status_active = in_array($state['status'], $valid_status, true) ? $state['status'] : '';
$storage_active = (int) ($_GET['storage'] ?? 0);
$search = $state['q'];

$locations = q_all('SELECT id, code FROM storage_locations ORDER BY code');

// ----- WHERE clause builder ------------------------------------------------

$where  = [];
$params = [];

if ($status_active !== '') {
    $where[]  = 'found_reports.status = ?';
    $params[] = $status_active;
}
if ($storage_active > 0) {
    $where[]  = 'found_reports.storage_location_id = ?';
    $params[] = $storage_active;
}
if ($search !== '') {
    $where[]  = '(found_reports.ref_number LIKE ? OR found_reports.description LIKE ?
                  OR found_reports.category LIKE ? OR found_reports.color LIKE ?
                  OR storage_locations.code LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int) (q_value(
    'SELECT COUNT(*)
       FROM found_reports
       JOIN storage_locations ON storage_locations.id = found_reports.storage_location_id
     ' . $where_sql,
    $params
) ?? 0);

$sortable = [
    'created' => 'found_reports.created_at',
    'ref'     => 'found_reports.ref_number',
    'date'    => 'found_reports.date_found',
];
$sort_col = $sortable[$state['sort']] ?? 'found_reports.created_at';
$sort_dir = $state['dir'] === 'asc' ? 'ASC' : 'DESC';
$offset   = ($state['page'] - 1) * $state['per_page'];

$rows = q_all(
    'SELECT found_reports.*,
            storage_locations.code AS storage_code,
            accounts.full_name     AS finder_name
       FROM found_reports
       JOIN storage_locations ON storage_locations.id = found_reports.storage_location_id
       JOIN accounts          ON accounts.id          = found_reports.finder_account_id
     ' . $where_sql . '
      ORDER BY ' . $sort_col . ' ' . $sort_dir . ', found_reports.id DESC
      LIMIT ? OFFSET ?',
    array_merge($params, [$state['per_page'], $offset])
);

// ----- URL helpers ---------------------------------------------------------

$base = array_filter([
    'p'        => 'found',
    'status'   => $state['status'],
    'q'        => $state['q'],
    'storage'  => $storage_active > 0 ? (string) $storage_active : '',
    'sort'     => $state['sort'] !== 'created' ? $state['sort'] : '',
    'dir'      => $state['dir']  !== 'desc'    ? $state['dir']  : '',
    'per_page' => $state['per_page'] !== 25 ? (string) $state['per_page'] : '',
], static fn($v) => $v !== '' && $v !== null);

$chip_url = static function (string $value) use ($state, $storage_active, $search): string {
    $p = ['p' => 'found'];
    if ($value !== '')        $p['status']  = $value;
    if ($storage_active > 0)  $p['storage'] = (string) $storage_active;
    if ($search !== '')       $p['q']       = $search;
    if ($state['sort'] !== 'created')     $p['sort']     = $state['sort'];
    if ($state['dir']  !== 'desc')        $p['dir']      = $state['dir'];
    if ($state['per_page'] !== 25)        $p['per_page'] = (string) $state['per_page'];
    return url('/index.php?' . http_build_query($p));
};

layout_open('Found Items');

$success = flash_get('success');
if ($success) {
    echo '<div class="alert alert-success" role="status">' . e($success) . '</div>';
}
$info = flash_get('info');
if ($info) {
    echo '<div class="alert alert-info" role="status">' . e($info) . '</div>';
}

breadcrumb([
    ['Dashboard',   url('/index.php?p=staff.dashboard')],
    ['Found Items'],
]);

page_header(
    'Found Items',
    '<a class="btn btn-primary" href="' . e(url('/index.php?p=found.new')) . '">Log Found Item</a>'
);
?>

<div class="card">
  <div class="filter-bar">
    <div class="filter-chips" role="tablist" aria-label="Filter by status">
      <a class="filter-chip<?= $state['status'] === ''         ? ' active' : '' ?>" href="<?= e($chip_url('')) ?>">All</a>
      <a class="filter-chip<?= $state['status'] === 'open'     ? ' active' : '' ?>" href="<?= e($chip_url('open')) ?>">Open</a>
      <a class="filter-chip<?= $state['status'] === 'matched'  ? ' active' : '' ?>" href="<?= e($chip_url('matched')) ?>">Matched</a>
      <a class="filter-chip<?= $state['status'] === 'claimed'  ? ' active' : '' ?>" href="<?= e($chip_url('claimed')) ?>">Claimed</a>
      <a class="filter-chip<?= $state['status'] === 'released' ? ' active' : '' ?>" href="<?= e($chip_url('released')) ?>">Released</a>
      <a class="filter-chip<?= $state['status'] === 'expired'  ? ' active' : '' ?>" href="<?= e($chip_url('expired')) ?>">Expired</a>
      <a class="filter-chip<?= $state['status'] === 'donated'  ? ' active' : '' ?>" href="<?= e($chip_url('donated')) ?>">Donated</a>
    </div>
    <form method="GET" class="filter-search" role="search">
      <input type="hidden" name="p" value="found">
      <?php if ($state['status'] !== ''): ?>
        <input type="hidden" name="status" value="<?= e($state['status']) ?>">
      <?php endif; ?>
      <?php if ($storage_active > 0): ?>
        <input type="hidden" name="storage" value="<?= e((string) $storage_active) ?>">
      <?php endif; ?>
      <input type="search" name="q" value="<?= e($search) ?>"
             placeholder="Ref, description, location code&hellip;"
             aria-label="Search found items">
      <button type="submit" class="btn btn-ghost btn-sm">Search</button>
    </form>
  </div>

  <?php if (!$rows): ?>
    <div class="empty-state">
      <?php if ($state['status'] !== '' || $search !== '' || $storage_active > 0): ?>
        <p class="empty-state-title">No items match those filters</p>
        <p class="empty-state-body">Try clearing the filter or searching different keywords.</p>
        <a class="btn btn-ghost" href="<?= e(url('/index.php?p=found')) ?>">Clear filters</a>
      <?php else: ?>
        <p class="empty-state-title">No found items logged yet</p>
        <p class="empty-state-body">When students or staff turn an item in, log it here so the system can match it against open lost reports.</p>
        <a class="btn btn-primary" href="<?= e(url('/index.php?p=found.new')) ?>">Log Found Item</a>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="table-wrap table-wrap-cards">
      <table class="data-table data-table-static">
        <thead>
          <tr>
            <th<?= sort_aria('ref', $state) ?>><?= sort_link('ref', 'Reference', $state, '', $base) ?></th>
            <th>Item</th>
            <th class="col-narrow">Location</th>
            <th class="col-narrow"<?= sort_aria('date', $state) ?>><?= sort_link('date', 'Date found', $state, '', $base) ?></th>
            <th class="col-narrow">Logged by</th>
            <th class="col-narrow">Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td data-label="Reference">
                <a href="<?= e(url('/index.php?p=found.show&id=' . (int) $r['id'])) ?>">
                  <?= e((string) $r['ref_number']) ?>
                </a>
              </td>
              <td data-label="Item">
                <strong><?= e(category_label((string) $r['category'])) ?></strong>
                <span class="text-muted text-sm"> &middot; <?= e((string) $r['color']) ?></span>
                <div class="text-sm text-muted"><?= e(mb_strimwidth((string) $r['description'], 0, 70, '…')) ?></div>
              </td>
              <td data-label="Location" class="col-narrow text-sm"><?= e((string) $r['storage_code']) ?></td>
              <td data-label="Date found" class="col-narrow text-sm text-muted">
                <?= e(date('M j, Y', strtotime((string) $r['date_found']))) ?>
              </td>
              <td data-label="Logged by" class="col-narrow text-sm"><?= e((string) $r['finder_name']) ?></td>
              <td data-label="Status" class="col-narrow"><?= status_badge((string) $r['status']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= render_pagination($total, $state, '', $base) ?>
  <?php endif; ?>
</div>

<?php
layout_close();
