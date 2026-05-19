<?php
declare(strict_types=1);

/**
 * Full claims list — staff/admin.
 * All statuses, filterable, searchable, paginated, oldest-first by default.
 */

$state = table_state('', [
    'sort'     => 'submitted',
    'dir'      => 'asc',
    'per_page' => 25,
]);

$valid_statuses  = ['pending_user_action', 'pending_verification', 'released', 'rejected'];
$status_active   = in_array($state['status'], $valid_statuses, true) ? $state['status'] : '';

$sortable_cols = [
    'submitted' => 'claim_tickets.created_at',
    'ref'       => 'claim_tickets.ref_number',
];

$where  = [];
$params = [];

if ($status_active !== '') {
    $where[]  = 'claim_tickets.status = ?';
    $params[] = $status_active;
}
if ($state['q'] !== '') {
    $where[]  = '(claim_tickets.ref_number LIKE ? OR lost_reports.ref_number LIKE ? OR accounts.full_name LIKE ?)';
    $like     = '%' . $state['q'] . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int) (q_value(
    'SELECT COUNT(*) FROM claim_tickets
       JOIN matches      ON matches.id      = claim_tickets.match_id
       JOIN lost_reports ON lost_reports.id = matches.lost_report_id
       JOIN accounts     ON accounts.id     = claim_tickets.claimant_account_id
      ' . $where_sql,
    $params
) ?? 0);

$sort_col = $sortable_cols[$state['sort']] ?? 'claim_tickets.created_at';
$sort_dir = $state['dir'] === 'asc' ? 'ASC' : 'DESC';
$offset   = ($state['page'] - 1) * $state['per_page'];

$claims = q_all(
    'SELECT claim_tickets.id           AS claim_id,
            claim_tickets.ref_number   AS claim_ref,
            claim_tickets.status       AS claim_status,
            claim_tickets.created_at   AS claim_created_at,
            claim_tickets.submitted_at AS submitted_at,
            accounts.full_name         AS claimant_name,
            accounts.id_number         AS claimant_id_number,
            lost_reports.id            AS lost_id,
            lost_reports.ref_number    AS lost_ref,
            lost_reports.category      AS lost_category,
            lost_reports.color         AS lost_color
       FROM claim_tickets
       JOIN matches      ON matches.id      = claim_tickets.match_id
       JOIN lost_reports ON lost_reports.id = matches.lost_report_id
       JOIN accounts     ON accounts.id     = claim_tickets.claimant_account_id
      ' . $where_sql . '
      ORDER BY ' . $sort_col . ' ' . $sort_dir . ', claim_tickets.id ASC
      LIMIT ? OFFSET ?',
    array_merge($params, [$state['per_page'], $offset])
);

$base = ['p' => 'staff.claims'];

$base_with_state = $base + array_filter([
    'status'   => $state['status'],
    'q'        => $state['q'],
    'sort'     => $state['sort'] !== 'submitted' ? $state['sort'] : '',
    'dir'      => $state['dir'] !== 'asc' ? $state['dir'] : '',
    'per_page' => $state['per_page'] !== 25 ? (string) $state['per_page'] : '',
], static fn($v) => $v !== '' && $v !== null);

$chip_url = static function (string $value) use ($base, $state): string {
    $p = $base;
    if ($value !== '') $p['status'] = $value;
    if ($state['q'] !== '')               $p['q']        = $state['q'];
    if ($state['sort'] !== 'submitted')   $p['sort']     = $state['sort'];
    if ($state['dir']  !== 'asc')         $p['dir']      = $state['dir'];
    if ($state['per_page'] !== 25)        $p['per_page'] = (string) $state['per_page'];
    return url('/index.php?' . http_build_query($p));
};

layout_open('All Claims');

page_header('All Claims');
?>

<div class="filter-bar">
  <div class="filter-chips" role="tablist" aria-label="Filter by status">
    <a class="filter-chip<?= $state['status'] === ''                     ? ' active' : '' ?>" href="<?= e($chip_url('')) ?>">All</a>
    <a class="filter-chip<?= $state['status'] === 'pending_user_action'  ? ' active' : '' ?>" href="<?= e($chip_url('pending_user_action')) ?>">Awaiting user</a>
    <a class="filter-chip<?= $state['status'] === 'pending_verification' ? ' active' : '' ?>" href="<?= e($chip_url('pending_verification')) ?>">Pending verification</a>
    <a class="filter-chip<?= $state['status'] === 'released'             ? ' active' : '' ?>" href="<?= e($chip_url('released')) ?>">Released</a>
    <a class="filter-chip<?= $state['status'] === 'rejected'             ? ' active' : '' ?>" href="<?= e($chip_url('rejected')) ?>">Rejected</a>
  </div>
  <form method="GET" class="filter-search" role="search">
    <input type="hidden" name="p" value="staff.claims">
    <?php foreach (['status', 'sort', 'dir', 'per_page'] as $k):
      if (!empty($_GET[$k])): ?>
        <input type="hidden" name="<?= e($k) ?>" value="<?= e((string) $_GET[$k]) ?>">
      <?php endif;
    endforeach; ?>
    <input type="search" name="q" value="<?= e($state['q']) ?>"
           placeholder="Claim ref, claimant&hellip;"
           aria-label="Search claims">
    <button type="submit" class="btn btn-ghost btn-sm">Search</button>
  </form>
</div>

<section class="card">
  <?php if (!$claims): ?>
    <div class="empty-state">
      <p class="empty-state-title">No claims found</p>
      <p class="empty-state-body">Try adjusting the filter or search term.</p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th<?= sort_aria('ref', $state) ?>><?= sort_link('ref', 'Reference', $state, '', $base_with_state) ?></th>
            <th>Claimant</th>
            <th>Item</th>
            <th class="col-narrow"<?= sort_aria('submitted', $state) ?>><?= sort_link('submitted', 'Created', $state, '', $base_with_state) ?></th>
            <th class="col-narrow">Status</th>
            <th class="col-actions">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($claims as $c): ?>
            <tr>
              <td>
                <a href="<?= e(url('/index.php?p=release&claim=' . (int) $c['claim_id'])) ?>">
                  <?= e((string) $c['claim_ref']) ?>
                </a>
              </td>
              <td>
                <?= e((string) $c['claimant_name']) ?>
                <div class="text-sm text-muted"><?= e((string) $c['claimant_id_number']) ?></div>
              </td>
              <td>
                <a href="<?= e(url('/index.php?p=lost.show&id=' . (int) $c['lost_id'])) ?>"><?= e((string) $c['lost_ref']) ?></a>
                <div class="text-sm text-muted">
                  <?= e(category_label((string) $c['lost_category'])) ?> &middot; <?= e((string) $c['lost_color']) ?>
                </div>
              </td>
              <td class="col-narrow text-sm text-muted">
                <?= e(time_ago((string) ($c['submitted_at'] ?? $c['claim_created_at']))) ?>
              </td>
              <td class="col-narrow"><?= status_badge((string) $c['claim_status']) ?></td>
              <td class="col-actions">
                <?php if (in_array($c['claim_status'], ['pending_user_action', 'pending_verification'], true)): ?>
                  <a class="btn btn-primary btn-sm" href="<?= e(url('/index.php?p=release&claim=' . (int) $c['claim_id'])) ?>">Verify</a>
                <?php else: ?>
                  <a class="btn btn-ghost btn-sm" href="<?= e(url('/index.php?p=release&claim=' . (int) $c['claim_id'])) ?>">View</a>
                <?php endif; ?>
              </td>
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
