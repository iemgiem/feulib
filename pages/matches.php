<?php
declare(strict_types=1);

/**
 * Full match list — staff/admin.
 * All statuses, filterable, searchable, paginated.
 */

$state = table_state('', [
    'sort'     => 'age',
    'dir'      => 'desc',
    'per_page' => 25,
]);

$valid_statuses = ['pending', 'approved', 'rejected', 'needs_info'];
$status_active  = in_array($state['status'], $valid_statuses, true) ? $state['status'] : '';

$sortable_cols = [
    'score' => 'matches.score',
    'age'   => 'matches.created_at',
];

$where  = [];
$params = [];

if ($status_active !== '') {
    $where[]  = 'matches.status = ?';
    $params[] = $status_active;
}
if ($state['q'] !== '') {
    $where[]  = '(lost_reports.ref_number LIKE ? OR found_reports.ref_number LIKE ? OR lost_reports.category LIKE ? OR lost_reports.description LIKE ?)';
    $like     = '%' . $state['q'] . '%';
    $params[] = $like; $params[] = $like;
    $params[] = $like; $params[] = $like;
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int) (q_value(
    'SELECT COUNT(*) FROM matches
       JOIN lost_reports  ON lost_reports.id  = matches.lost_report_id
       JOIN found_reports ON found_reports.id = matches.found_report_id
      ' . $where_sql,
    $params
) ?? 0);

$sort_col = $sortable_cols[$state['sort']] ?? 'matches.created_at';
$sort_dir = $state['dir'] === 'asc' ? 'ASC' : 'DESC';
$offset   = ($state['page'] - 1) * $state['per_page'];

$matches = q_all(
    'SELECT matches.id            AS match_id,
            matches.score         AS score,
            matches.status        AS match_status,
            matches.is_suspicious AS suspicious,
            matches.created_at    AS match_created_at,
            lost_reports.id          AS lost_id,
            lost_reports.ref_number  AS lost_ref,
            lost_reports.category    AS lost_category,
            lost_reports.color       AS lost_color,
            found_reports.id         AS found_id,
            found_reports.ref_number AS found_ref,
            found_reports.category   AS found_category,
            found_reports.color      AS found_color
       FROM matches
       JOIN lost_reports  ON lost_reports.id  = matches.lost_report_id
       JOIN found_reports ON found_reports.id = matches.found_report_id
      ' . $where_sql . '
      ORDER BY ' . $sort_col . ' ' . $sort_dir . ', matches.id DESC
      LIMIT ? OFFSET ?',
    array_merge($params, [$state['per_page'], $offset])
);

$base = ['p' => 'matches'];

$base_with_state = $base + array_filter([
    'status'   => $state['status'],
    'q'        => $state['q'],
    'sort'     => $state['sort'] !== 'age' ? $state['sort'] : '',
    'dir'      => $state['dir'] !== 'desc' ? $state['dir'] : '',
    'per_page' => $state['per_page'] !== 25 ? (string) $state['per_page'] : '',
], static fn($v) => $v !== '' && $v !== null);

$chip_url = static function (string $value) use ($base, $state): string {
    $p = $base;
    if ($value !== '') $p['status'] = $value;
    if ($state['q'] !== '')          $p['q']        = $state['q'];
    if ($state['sort'] !== 'age')    $p['sort']     = $state['sort'];
    if ($state['dir']  !== 'desc')   $p['dir']      = $state['dir'];
    if ($state['per_page'] !== 25)   $p['per_page'] = (string) $state['per_page'];
    return url('/index.php?' . http_build_query($p));
};

layout_open('All Matches');

page_header('All Matches');
?>

<section class="card">
  <div class="filter-bar">
    <div class="filter-chips" role="tablist" aria-label="Filter by status">
      <a class="filter-chip<?= $state['status'] === ''           ? ' active' : '' ?>" href="<?= e($chip_url('')) ?>">All</a>
      <a class="filter-chip<?= $state['status'] === 'pending'    ? ' active' : '' ?>" href="<?= e($chip_url('pending')) ?>">Pending</a>
      <a class="filter-chip<?= $state['status'] === 'needs_info' ? ' active' : '' ?>" href="<?= e($chip_url('needs_info')) ?>">Needs Info</a>
      <a class="filter-chip<?= $state['status'] === 'approved'   ? ' active' : '' ?>" href="<?= e($chip_url('approved')) ?>">Approved</a>
      <a class="filter-chip<?= $state['status'] === 'rejected'   ? ' active' : '' ?>" href="<?= e($chip_url('rejected')) ?>">Rejected</a>
    </div>
    <form method="GET" class="filter-search" role="search">
      <input type="hidden" name="p" value="matches">
      <?php foreach (['status', 'sort', 'dir', 'per_page'] as $k):
        if (!empty($_GET[$k])): ?>
          <input type="hidden" name="<?= e($k) ?>" value="<?= e((string) $_GET[$k]) ?>">
        <?php endif;
      endforeach; ?>
      <input type="search" name="q" value="<?= e($state['q']) ?>"
             placeholder="Ref, category, description&hellip;"
             aria-label="Search matches">
      <button type="submit" class="btn btn-ghost btn-sm">Search</button>
    </form>
  </div>
  <?php if (!$matches): ?>
    <div class="empty-state">
      <p class="empty-state-title">No matches found</p>
      <p class="empty-state-body">Try adjusting the filter or search term.</p>
    </div>
  <?php else: ?>
    <div class="table-wrap table-wrap-cards">
      <table class="data-table data-table-static">
        <thead>
          <tr>
            <th class="col-narrow"<?= sort_aria('score', $state) ?>><?= sort_link('score', 'Score', $state, '', $base_with_state) ?></th>
            <th>Lost item</th>
            <th>Found item</th>
            <th class="col-narrow"<?= sort_aria('age', $state) ?>><?= sort_link('age', 'Age', $state, '', $base_with_state) ?></th>
            <th class="col-narrow">Status</th>
            <th class="col-actions">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($matches as $m): ?>
            <tr>
              <td data-label="Score" class="col-narrow"><?= score_chip((int) $m['score']) ?></td>
              <td data-label="Lost item">
                <a href="<?= e(url('/index.php?p=lost.show&id=' . (int) $m['lost_id'])) ?>"><?= e((string) $m['lost_ref']) ?></a>
                <div class="text-sm text-muted">
                  <?= e(category_label((string) $m['lost_category'])) ?> &middot; <?= e((string) $m['lost_color']) ?>
                </div>
              </td>
              <td data-label="Found item">
                <a href="<?= e(url('/index.php?p=found.show&id=' . (int) $m['found_id'])) ?>"><?= e((string) $m['found_ref']) ?></a>
                <div class="text-sm text-muted">
                  <?= e(category_label((string) $m['found_category'])) ?> &middot; <?= e((string) $m['found_color']) ?>
                </div>
              </td>
              <td data-label="Age" class="col-narrow text-sm text-muted"><?= e(time_ago((string) $m['match_created_at'])) ?></td>
              <td data-label="Status" class="col-narrow"><?= status_badge((string) $m['match_status']) ?></td>
              <td data-label="Action" class="col-actions">
                <a class="btn btn-primary btn-sm" href="<?= e(url('/index.php?p=match.show&id=' . (int) $m['match_id'])) ?>">Review</a>
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
