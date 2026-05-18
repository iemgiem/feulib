<?php
declare(strict_types=1);

/**
 * Staff Dashboard — operational hub.
 *
 * Layout:
 *   - 4 stat cards (open lost / open found / matches pending / claims pending)
 *   - Match Validation Queue: filter chips + search + sortable + paginated
 *   - Claims Queue: filter chips + search + paginated (sorted oldest-first)
 *
 * Each table maintains independent URL state via prefixed params:
 *   m_status, m_q, m_sort, m_dir, m_page, m_per_page  (match queue)
 *   c_status, c_q, c_sort, c_dir, c_page, c_per_page  (claims queue)
 */

$user = current_user();

// -----------------------------------------------------------------------------
// Stat strip
// -----------------------------------------------------------------------------

$stats = [
    'open_lost' => (int) (q_value(
        "SELECT COUNT(*) FROM lost_reports WHERE status = 'open'"
    ) ?? 0),
    'open_found' => (int) (q_value(
        "SELECT COUNT(*) FROM found_reports WHERE status = 'open'"
    ) ?? 0),
    'pending_matches' => (int) (q_value(
        "SELECT COUNT(*) FROM matches WHERE status IN ('pending', 'needs_info')"
    ) ?? 0),
    'pending_claims' => (int) (q_value(
        "SELECT COUNT(*) FROM claim_tickets WHERE status IN ('pending_user_action', 'pending_verification')"
    ) ?? 0),
];

// -----------------------------------------------------------------------------
// Match Validation Queue
// -----------------------------------------------------------------------------

$m_state = table_state('m_', [
    'sort'     => 'score',
    'dir'      => 'desc',
    'per_page' => 10,
]);

$m_valid_status   = ['pending', 'needs_info'];
$m_sortable_cols  = [
    'score' => 'matches.score',
    'age'   => 'matches.created_at',
];
$m_status_active  = in_array($m_state['status'], $m_valid_status, true) ? $m_state['status'] : '';

$m_where  = [];
$m_params = [];
if ($m_status_active !== '') {
    $m_where[]  = 'matches.status = ?';
    $m_params[] = $m_status_active;
} else {
    $m_where[] = "matches.status IN ('pending', 'needs_info')";
}
if ($m_state['q'] !== '') {
    $m_where[]  = '(lost_reports.ref_number LIKE ? OR found_reports.ref_number LIKE ? OR lost_reports.category LIKE ? OR lost_reports.description LIKE ?)';
    $like = '%' . $m_state['q'] . '%';
    $m_params[] = $like; $m_params[] = $like;
    $m_params[] = $like; $m_params[] = $like;
}

$m_total = (int) (q_value(
    'SELECT COUNT(*) FROM matches
       JOIN lost_reports  ON lost_reports.id  = matches.lost_report_id
       JOIN found_reports ON found_reports.id = matches.found_report_id
      WHERE ' . implode(' AND ', $m_where),
    $m_params
) ?? 0);

$m_sort_col = $m_sortable_cols[$m_state['sort']] ?? 'matches.score';
$m_sort_dir = $m_state['dir'] === 'asc' ? 'ASC' : 'DESC';
$m_offset   = ($m_state['page'] - 1) * $m_state['per_page'];

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
            lost_reports.description AS lost_description,
            found_reports.id         AS found_id,
            found_reports.ref_number AS found_ref,
            found_reports.category   AS found_category,
            found_reports.color      AS found_color
       FROM matches
       JOIN lost_reports  ON lost_reports.id  = matches.lost_report_id
       JOIN found_reports ON found_reports.id = matches.found_report_id
      WHERE ' . implode(' AND ', $m_where) . '
      ORDER BY ' . $m_sort_col . ' ' . $m_sort_dir . ', matches.id DESC
      LIMIT ? OFFSET ?',
    array_merge($m_params, [$m_state['per_page'], $m_offset])
);

// -----------------------------------------------------------------------------
// Claims Queue (oldest first by default)
// -----------------------------------------------------------------------------

$c_state = table_state('c_', [
    'sort'     => 'submitted',
    'dir'      => 'asc',
    'per_page' => 10,
]);

$c_valid_status = ['pending_user_action', 'pending_verification'];
$c_status_active = in_array($c_state['status'], $c_valid_status, true) ? $c_state['status'] : '';

$c_where  = [];
$c_params = [];
if ($c_status_active !== '') {
    $c_where[]  = 'claim_tickets.status = ?';
    $c_params[] = $c_status_active;
} else {
    $c_where[] = "claim_tickets.status IN ('pending_user_action', 'pending_verification')";
}
if ($c_state['q'] !== '') {
    $c_where[]  = '(claim_tickets.ref_number LIKE ? OR lost_reports.ref_number LIKE ? OR accounts.full_name LIKE ?)';
    $like = '%' . $c_state['q'] . '%';
    $c_params[] = $like; $c_params[] = $like; $c_params[] = $like;
}

$c_total = (int) (q_value(
    'SELECT COUNT(*) FROM claim_tickets
       JOIN matches      ON matches.id      = claim_tickets.match_id
       JOIN lost_reports ON lost_reports.id = matches.lost_report_id
       JOIN accounts     ON accounts.id     = claim_tickets.claimant_account_id
      WHERE ' . implode(' AND ', $c_where),
    $c_params
) ?? 0);

$c_sortable_cols = [
    'submitted' => 'claim_tickets.created_at',
    'ref'       => 'claim_tickets.ref_number',
];
$c_sort_col = $c_sortable_cols[$c_state['sort']] ?? 'claim_tickets.created_at';
$c_sort_dir = $c_state['dir'] === 'asc' ? 'ASC' : 'DESC';
$c_offset   = ($c_state['page'] - 1) * $c_state['per_page'];

$claims = q_all(
    'SELECT claim_tickets.id           AS claim_id,
            claim_tickets.ref_number   AS claim_ref,
            claim_tickets.status       AS claim_status,
            claim_tickets.created_at   AS claim_created_at,
            claim_tickets.submitted_at AS submitted_at,
            accounts.full_name         AS claimant_name,
            accounts.id_number         AS claimant_id_number,
            lost_reports.ref_number    AS lost_ref,
            lost_reports.category      AS lost_category,
            lost_reports.color         AS lost_color
       FROM claim_tickets
       JOIN matches      ON matches.id      = claim_tickets.match_id
       JOIN lost_reports ON lost_reports.id = matches.lost_report_id
       JOIN accounts     ON accounts.id     = claim_tickets.claimant_account_id
      WHERE ' . implode(' AND ', $c_where) . '
      ORDER BY ' . $c_sort_col . ' ' . $c_sort_dir . ', claim_tickets.id ASC
      LIMIT ? OFFSET ?',
    array_merge($c_params, [$c_state['per_page'], $c_offset])
);

// -----------------------------------------------------------------------------
// Base param sets for sort / pagination URL building
// -----------------------------------------------------------------------------

$base = ['p' => 'staff.dashboard'];

$m_base = $base + array_filter([
    'm_status'   => $m_state['status'],
    'm_q'        => $m_state['q'],
    'm_sort'     => $m_state['sort'],
    'm_dir'      => $m_state['dir'],
    'm_per_page' => $m_state['per_page'] !== 10 ? (string) $m_state['per_page'] : '',
    'c_status'   => $c_state['status'],
    'c_q'        => $c_state['q'],
    'c_sort'     => $c_state['sort'],
    'c_dir'      => $c_state['dir'],
    'c_per_page' => $c_state['per_page'] !== 10 ? (string) $c_state['per_page'] : '',
    'c_page'     => $c_state['page'] !== 1 ? (string) $c_state['page'] : '',
], static fn($v) => $v !== '' && $v !== null);

$c_base = $base + array_filter([
    'c_status'   => $c_state['status'],
    'c_q'        => $c_state['q'],
    'c_sort'     => $c_state['sort'],
    'c_dir'      => $c_state['dir'],
    'c_per_page' => $c_state['per_page'] !== 10 ? (string) $c_state['per_page'] : '',
    'm_status'   => $m_state['status'],
    'm_q'        => $m_state['q'],
    'm_sort'     => $m_state['sort'],
    'm_dir'      => $m_state['dir'],
    'm_per_page' => $m_state['per_page'] !== 10 ? (string) $m_state['per_page'] : '',
    'm_page'     => $m_state['page'] !== 1 ? (string) $m_state['page'] : '',
], static fn($v) => $v !== '' && $v !== null);

// Helper: build a filter-chip URL for a given table (resets that table's page).
$chip_url = static function (string $prefix, string $param, string $value) use ($base, $m_state, $c_state): string {
    $p = $base;
    if ($prefix === 'm_') {
        if ($value !== '') $p['m_status'] = $value;
        if ($m_state['q'] !== '') $p['m_q'] = $m_state['q'];
        if ($m_state['sort'] !== 'score') $p['m_sort'] = $m_state['sort'];
        if ($m_state['dir']  !== 'desc')  $p['m_dir']  = $m_state['dir'];
        if ($m_state['per_page'] !== 10)  $p['m_per_page'] = (string) $m_state['per_page'];
        // preserve the other table's state
        if ($c_state['status'] !== '')    $p['c_status'] = $c_state['status'];
        if ($c_state['q']      !== '')    $p['c_q']      = $c_state['q'];
        if ($c_state['page']   !== 1)     $p['c_page']   = (string) $c_state['page'];
    } else {
        if ($value !== '') $p['c_status'] = $value;
        if ($c_state['q'] !== '') $p['c_q'] = $c_state['q'];
        if ($c_state['sort'] !== 'submitted') $p['c_sort'] = $c_state['sort'];
        if ($c_state['dir']  !== 'asc')       $p['c_dir']  = $c_state['dir'];
        if ($c_state['per_page'] !== 10)      $p['c_per_page'] = (string) $c_state['per_page'];
        if ($m_state['status'] !== '')        $p['m_status'] = $m_state['status'];
        if ($m_state['q']      !== '')        $p['m_q']      = $m_state['q'];
        if ($m_state['page']   !== 1)         $p['m_page']   = (string) $m_state['page'];
    }
    return url('/index.php?' . http_build_query($p));
};

// -----------------------------------------------------------------------------
// Render
// -----------------------------------------------------------------------------

layout_open('Staff Dashboard');

$success = flash_get('success');
if ($success) {
    echo '<div class="alert alert-success" role="status">' . e($success) . '</div>';
}

page_header(
    'Staff dashboard',
    '<a class="btn btn-primary" href="' . e(url('/index.php?p=found.new')) . '">Log Found Item</a>'
);
?>

<div class="stat-strip">
  <div class="stat-card">
    <div class="stat-card-value"><?= e((string) $stats['open_lost']) ?></div>
    <div class="stat-card-label">Open Lost</div>
  </div>
  <div class="stat-card">
    <div class="stat-card-value"><?= e((string) $stats['open_found']) ?></div>
    <div class="stat-card-label">Open Found</div>
  </div>
  <div class="stat-card">
    <div class="stat-card-value"><?= e((string) $stats['pending_matches']) ?></div>
    <div class="stat-card-label">Pending Matches</div>
  </div>
  <div class="stat-card">
    <div class="stat-card-value"><?= e((string) $stats['pending_claims']) ?></div>
    <div class="stat-card-label">Pending Claims</div>
  </div>
</div>

<!-- ==================================================================== -->
<!-- Match Validation Queue                                                -->
<!-- ==================================================================== -->
<section class="card" aria-labelledby="match-queue-title">
  <div class="card-header">
    <h2 class="card-title" id="match-queue-title">Match Validation Queue</h2>
    <a class="card-header-link" href="<?= e(url('/index.php?p=matches')) ?>">Full list</a>
  </div>

  <div class="filter-bar">
    <div class="filter-chips" role="tablist" aria-label="Filter matches by status">
      <a class="filter-chip<?= $m_state['status'] === ''           ? ' active' : '' ?>" href="<?= e($chip_url('m_', 'status', '')) ?>">All open</a>
      <a class="filter-chip<?= $m_state['status'] === 'pending'    ? ' active' : '' ?>" href="<?= e($chip_url('m_', 'status', 'pending')) ?>">Pending</a>
      <a class="filter-chip<?= $m_state['status'] === 'needs_info' ? ' active' : '' ?>" href="<?= e($chip_url('m_', 'status', 'needs_info')) ?>">Needs Info</a>
    </div>
    <form method="GET" class="filter-search" role="search">
      <input type="hidden" name="p" value="staff.dashboard">
      <?php foreach (['m_status','c_status','c_q','c_sort','c_dir','c_per_page','c_page'] as $k):
        if (!empty($_GET[$k])): ?>
          <input type="hidden" name="<?= e($k) ?>" value="<?= e((string) $_GET[$k]) ?>">
        <?php endif;
      endforeach; ?>
      <input type="search" name="m_q" value="<?= e($m_state['q']) ?>"
             placeholder="Ref, category, description&hellip;"
             aria-label="Search matches">
      <button type="submit" class="btn btn-ghost btn-sm">Search</button>
    </form>
  </div>

  <?php if (!$matches): ?>
    <div class="empty-state">
      <p class="empty-state-title">No matches to validate</p>
      <p class="empty-state-body">When the system proposes a match, it will appear here for staff approval.</p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th class="col-narrow"><?= sort_link('score', 'Score', $m_state, 'm_', $m_base) ?></th>
            <th>Lost item</th>
            <th>Found item</th>
            <th class="col-narrow"><?= sort_link('age', 'Age', $m_state, 'm_', $m_base) ?></th>
            <th class="col-narrow">Status</th>
            <th class="col-actions">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($matches as $m): ?>
            <tr>
              <td class="col-narrow"><?= score_chip((int) $m['score']) ?></td>
              <td>
                <a href="<?= e(url('/index.php?p=lost.show&id=' . (int) $m['lost_id'])) ?>"><?= e($m['lost_ref']) ?></a>
                <div class="text-sm text-muted">
                  <?= e(ucfirst((string) $m['lost_category'])) ?> · <?= e((string) $m['lost_color']) ?>
                </div>
              </td>
              <td>
                <a href="<?= e(url('/index.php?p=found.show&id=' . (int) $m['found_id'])) ?>"><?= e($m['found_ref']) ?></a>
                <div class="text-sm text-muted">
                  <?= e(ucfirst((string) $m['found_category'])) ?> · <?= e((string) $m['found_color']) ?>
                </div>
              </td>
              <td class="col-narrow text-sm text-muted">
                <?= e(time_ago((string) $m['match_created_at'])) ?>
              </td>
              <td class="col-narrow"><?= status_badge((string) $m['match_status']) ?></td>
              <td class="col-actions">
                <a class="btn btn-primary btn-sm" href="<?= e(url('/index.php?p=match.show&id=' . (int) $m['match_id'])) ?>">Review</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= render_pagination($m_total, $m_state, 'm_', $m_base) ?>
  <?php endif; ?>
</section>

<!-- ==================================================================== -->
<!-- Claims Queue                                                          -->
<!-- ==================================================================== -->
<section class="card" aria-labelledby="claims-queue-title">
  <div class="card-header">
    <h2 class="card-title" id="claims-queue-title">Claims Queue</h2>
    <a class="card-header-link" href="<?= e(url('/index.php?p=staff.claims')) ?>">Full list</a>
  </div>

  <div class="filter-bar">
    <div class="filter-chips" role="tablist" aria-label="Filter claims by status">
      <a class="filter-chip<?= $c_state['status'] === ''                     ? ' active' : '' ?>" href="<?= e($chip_url('c_', 'status', '')) ?>">All open</a>
      <a class="filter-chip<?= $c_state['status'] === 'pending_user_action'  ? ' active' : '' ?>" href="<?= e($chip_url('c_', 'status', 'pending_user_action')) ?>">Awaiting user</a>
      <a class="filter-chip<?= $c_state['status'] === 'pending_verification' ? ' active' : '' ?>" href="<?= e($chip_url('c_', 'status', 'pending_verification')) ?>">Pending verification</a>
    </div>
    <form method="GET" class="filter-search" role="search">
      <input type="hidden" name="p" value="staff.dashboard">
      <?php foreach (['c_status','m_status','m_q','m_sort','m_dir','m_per_page','m_page'] as $k):
        if (!empty($_GET[$k])): ?>
          <input type="hidden" name="<?= e($k) ?>" value="<?= e((string) $_GET[$k]) ?>">
        <?php endif;
      endforeach; ?>
      <input type="search" name="c_q" value="<?= e($c_state['q']) ?>"
             placeholder="Claim ref, claimant&hellip;"
             aria-label="Search claims">
      <button type="submit" class="btn btn-ghost btn-sm">Search</button>
    </form>
  </div>

  <?php if (!$claims): ?>
    <div class="empty-state">
      <p class="empty-state-title">No claims pending</p>
      <p class="empty-state-body">Claims appear here after a user submits a claim against an approved match.</p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th><?= sort_link('ref', 'Reference', $c_state, 'c_', $c_base) ?></th>
            <th>Claimant</th>
            <th>Item</th>
            <th class="col-narrow"><?= sort_link('submitted', 'Submitted', $c_state, 'c_', $c_base) ?></th>
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
                <a href="<?= e(url('/index.php?p=lost.show&id=' . (int) ($c['lost_id'] ?? 0))) ?>"><?= e((string) $c['lost_ref']) ?></a>
                <div class="text-sm text-muted">
                  <?= e(ucfirst((string) $c['lost_category'])) ?> · <?= e((string) $c['lost_color']) ?>
                </div>
              </td>
              <td class="col-narrow text-sm text-muted">
                <?= e(time_ago((string) ($c['submitted_at'] ?? $c['claim_created_at']))) ?>
              </td>
              <td class="col-narrow"><?= status_badge((string) $c['claim_status']) ?></td>
              <td class="col-actions">
                <a class="btn btn-primary btn-sm" href="<?= e(url('/index.php?p=release&claim=' . (int) $c['claim_id'])) ?>">Verify</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= render_pagination($c_total, $c_state, 'c_', $c_base) ?>
  <?php endif; ?>
</section>

<?php
layout_close();
