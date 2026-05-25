<?php
declare(strict_types=1);

/**
 * My Lost Reports — list view with filter chips + search.
 *
 *   ?p=lost                        all of user's reports
 *   ?p=lost&status=open            single-status filter
 *   ?p=lost&q=jansport             keyword search (ref / desc / category / color)
 *   ?p=lost&status=open&q=...      combined
 *
 * Pagination is intentionally minimal in v1 (LIMIT 100). Task 9 establishes
 * full pagination + sticky header for staff tables.
 */

$user    = current_user();
$user_id = (int) $user['id'];

// ----- Filter inputs --------------------------------------------------------

$valid_statuses = ['open', 'matched', 'claimed', 'released', 'expired'];
$status = isset($_GET['status']) && is_string($_GET['status']) ? trim($_GET['status']) : '';
if ($status !== '' && !in_array($status, $valid_statuses, true)) {
    $status = '';
}
$search = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';

// ----- Query ----------------------------------------------------------------

$where  = ['reporter_account_id = ?'];
$params = [$user_id];

if ($status !== '') {
    $where[]  = 'status = ?';
    $params[] = $status;
}
if ($search !== '') {
    $where[]  = '(ref_number LIKE ? OR description LIKE ? OR category LIKE ? OR color LIKE ?)';
    $like     = '%' . $search . '%';
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
}

$rows = q_all(
    'SELECT id, ref_number, category, color, description, date_lost, status, created_at
       FROM lost_reports
      WHERE ' . implode(' AND ', $where) . '
      ORDER BY created_at DESC
      LIMIT 100',
    $params
);

// ----- Helpers --------------------------------------------------------------

$chip_url = function (string $new_status) use ($search): string {
    $p = ['p' => 'lost'];
    if ($new_status !== '') $p['status'] = $new_status;
    if ($search !== '')     $p['q']      = $search;
    return url('/index.php?' . http_build_query($p));
};

// ----- Render ---------------------------------------------------------------

layout_open('My Lost Reports');

$success = flash_get('success');
if ($success) {
    echo '<div class="alert alert-success" role="status">' . e($success) . '</div>';
}
$info = flash_get('info');
if ($info) {
    echo '<div class="alert alert-info" role="status">' . e($info) . '</div>';
}

breadcrumb([
    ['Dashboard', url('/index.php?p=dashboard')],
    ['My Lost Reports'],
]);

page_header(
    'My Lost Reports',
    '<a class="btn btn-primary" href="' . e(url('/index.php?p=lost.new')) . '">Report a Lost Item</a>'
);
?>

<div class="card">
  <div class="filter-bar">
    <div class="filter-chips" role="tablist" aria-label="Filter by status">
      <a class="filter-chip<?= $status === ''        ? ' active' : '' ?>" href="<?= e($chip_url('')) ?>">All</a>
      <a class="filter-chip<?= $status === 'open'    ? ' active' : '' ?>" href="<?= e($chip_url('open')) ?>">Open</a>
      <a class="filter-chip<?= $status === 'matched' ? ' active' : '' ?>" href="<?= e($chip_url('matched')) ?>">Matched</a>
      <a class="filter-chip<?= $status === 'claimed' ? ' active' : '' ?>" href="<?= e($chip_url('claimed')) ?>">Claimed</a>
      <a class="filter-chip<?= $status === 'released'? ' active' : '' ?>" href="<?= e($chip_url('released')) ?>">Released</a>
      <a class="filter-chip<?= $status === 'expired' ? ' active' : '' ?>" href="<?= e($chip_url('expired')) ?>">Expired</a>
    </div>
    <form method="GET" class="filter-search" role="search">
      <input type="hidden" name="p" value="lost">
      <?php if ($status !== ''): ?>
        <input type="hidden" name="status" value="<?= e($status) ?>">
      <?php endif; ?>
      <input type="search" name="q" value="<?= e($search) ?>"
             placeholder="Search reference, description, color&hellip;"
             aria-label="Search reports">
      <button type="submit" class="btn btn-ghost btn-sm">Search</button>
    </form>
  </div>

  <?php if (!$rows): ?>
    <div class="empty-state">
      <?php if ($status !== '' || $search !== ''): ?>
        <p class="empty-state-title">No reports matched those filters</p>
        <p class="empty-state-body">Try clearing the filter or searching different keywords.</p>
        <a class="btn btn-ghost" href="<?= e(url('/index.php?p=lost')) ?>">Clear filters</a>
      <?php else: ?>
        <p class="empty-state-title">You haven't reported anything yet</p>
        <p class="empty-state-body">Report your first lost item to start the matching process.</p>
        <a class="btn btn-primary" href="<?= e(url('/index.php?p=lost.new')) ?>">Report a Lost Item</a>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="table-wrap table-wrap-cards">
      <table class="data-table data-table-static">
        <thead>
          <tr>
            <th>Reference</th>
            <th>Item</th>
            <th class="col-narrow">Date lost</th>
            <th class="col-narrow">Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td data-label="Reference">
                <a href="<?= e(url('/index.php?p=lost.show&id=' . (int) $r['id'])) ?>">
                  <?= e($r['ref_number']) ?>
                </a>
              </td>
              <td data-label="Item">
                <strong><?= e(ucfirst((string) $r['category'])) ?></strong>
                <span class="text-muted text-sm"> &middot; <?= e(mb_strimwidth((string) $r['description'], 0, 80, '…')) ?></span>
              </td>
              <td class="col-narrow text-sm text-muted" data-label="Date lost">
                <?= e(date('M j, Y', strtotime((string) $r['date_lost']))) ?>
              </td>
              <td class="col-narrow" data-label="Status"><?= status_badge((string) $r['status']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if (count($rows) === 100): ?>
      <p class="text-muted text-sm mt-3 text-center">
        Showing the most recent 100 reports. Full pagination arrives in Task 9.
      </p>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php
layout_close();
