<?php
declare(strict_types=1);

/**
 * My claims — user's list of claim tickets, paginated.
 */

$user    = current_user();
$user_id = (int) $user['id'];

$state = table_state('', [
    'sort'     => 'created',
    'dir'      => 'desc',
    'per_page' => 25,
]);

$valid_statuses = ['pending_user_action', 'pending_verification', 'released', 'rejected'];
$status_active  = in_array($state['status'], $valid_statuses, true) ? $state['status'] : '';

$where  = ['claim_tickets.claimant_account_id = ?'];
$params = [$user_id];

if ($status_active !== '') {
    $where[]  = 'claim_tickets.status = ?';
    $params[] = $status_active;
}

$where_sql = 'WHERE ' . implode(' AND ', $where);

$total = (int) (q_value(
    'SELECT COUNT(*) FROM claim_tickets
       JOIN matches      ON matches.id      = claim_tickets.match_id
       JOIN lost_reports ON lost_reports.id = matches.lost_report_id
      ' . $where_sql,
    $params
) ?? 0);

$sort_col = $state['sort'] === 'ref' ? 'claim_tickets.ref_number' : 'claim_tickets.created_at';
$sort_dir = $state['dir'] === 'asc' ? 'ASC' : 'DESC';
$offset   = ($state['page'] - 1) * $state['per_page'];

$claims = q_all(
    'SELECT claim_tickets.id           AS claim_id,
            claim_tickets.ref_number   AS claim_ref,
            claim_tickets.status       AS claim_status,
            claim_tickets.created_at   AS claim_created_at,
            claim_tickets.submitted_at AS submitted_at,
            lost_reports.ref_number    AS lost_ref,
            lost_reports.category      AS lost_category,
            lost_reports.color         AS lost_color
       FROM claim_tickets
       JOIN matches      ON matches.id      = claim_tickets.match_id
       JOIN lost_reports ON lost_reports.id = matches.lost_report_id
      ' . $where_sql . '
      ORDER BY ' . $sort_col . ' ' . $sort_dir . '
      LIMIT ? OFFSET ?',
    array_merge($params, [$state['per_page'], $offset])
);

$base = ['p' => 'claims'];

$base_with_state = $base + array_filter([
    'status'   => $state['status'],
    'q'        => $state['q'],
    'sort'     => $state['sort'] !== 'created' ? $state['sort'] : '',
    'dir'      => $state['dir'] !== 'desc' ? $state['dir'] : '',
    'per_page' => $state['per_page'] !== 25 ? (string) $state['per_page'] : '',
], static fn($v) => $v !== '' && $v !== null);

$chip_url = static function (string $value) use ($base, $state): string {
    $p = $base;
    if ($value !== '') $p['status'] = $value;
    if ($state['sort'] !== 'created') $p['sort'] = $state['sort'];
    if ($state['dir']  !== 'desc')    $p['dir']  = $state['dir'];
    if ($state['per_page'] !== 25)    $p['per_page'] = (string) $state['per_page'];
    return url('/index.php?' . http_build_query($p));
};

layout_open('My Claims');

page_header('My Claims');
?>

<div class="filter-bar">
  <div class="filter-chips" role="tablist" aria-label="Filter by status">
    <a class="filter-chip<?= $state['status'] === ''                     ? ' active' : '' ?>" href="<?= e($chip_url('')) ?>">All</a>
    <a class="filter-chip<?= $state['status'] === 'pending_user_action'  ? ' active' : '' ?>" href="<?= e($chip_url('pending_user_action')) ?>">Action needed</a>
    <a class="filter-chip<?= $state['status'] === 'pending_verification' ? ' active' : '' ?>" href="<?= e($chip_url('pending_verification')) ?>">Awaiting pickup</a>
    <a class="filter-chip<?= $state['status'] === 'released'             ? ' active' : '' ?>" href="<?= e($chip_url('released')) ?>">Released</a>
    <a class="filter-chip<?= $state['status'] === 'rejected'             ? ' active' : '' ?>" href="<?= e($chip_url('rejected')) ?>">Rejected</a>
  </div>
</div>

<section class="card">
  <?php if (!$claims): ?>
    <div class="empty-state">
      <p class="empty-state-title">No claims yet</p>
      <p class="empty-state-body">
        When staff approve a match for one of your lost reports, a claim will appear here.
      </p>
      <a class="btn btn-primary" href="<?= e(url('/index.php?p=dashboard')) ?>">Back to dashboard</a>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th<?= sort_aria('ref', $state) ?>><?= sort_link('ref', 'Reference', $state, '', $base_with_state) ?></th>
            <th>Item</th>
            <th class="col-narrow"<?= sort_aria('created', $state) ?>><?= sort_link('created', 'Created', $state, '', $base_with_state) ?></th>
            <th class="col-narrow">Status</th>
            <th class="col-actions">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($claims as $c): ?>
            <tr>
              <td>
                <a href="<?= e(url('/index.php?p=claim.show&id=' . (int) $c['claim_id'])) ?>">
                  <?= e((string) $c['claim_ref']) ?>
                </a>
              </td>
              <td>
                <?= e(category_label((string) $c['lost_category'])) ?>
                <div class="text-sm text-muted"><?= e((string) $c['lost_color']) ?> &middot; <?= e((string) $c['lost_ref']) ?></div>
              </td>
              <td class="col-narrow text-sm text-muted">
                <?= e(time_ago((string) $c['claim_created_at'])) ?>
              </td>
              <td class="col-narrow"><?= status_badge((string) $c['claim_status']) ?></td>
              <td class="col-actions">
                <?php if ($c['claim_status'] === 'pending_user_action'): ?>
                  <a class="btn btn-primary btn-sm" href="<?= e(url('/index.php?p=claim.new&claim=' . (int) $c['claim_id'])) ?>">Upload ID</a>
                <?php else: ?>
                  <a class="btn btn-ghost btn-sm" href="<?= e(url('/index.php?p=claim.show&id=' . (int) $c['claim_id'])) ?>">View</a>
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
