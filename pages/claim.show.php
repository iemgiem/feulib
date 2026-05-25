<?php
declare(strict_types=1);

/**
 * Claim detail — user view of their own claim.
 *
 * Shows status, next steps, uploaded ID proofs, and (if released) the
 * release confirmation. Staff/admin also see this page via the sidebar.
 */

$user    = current_user();
$user_id = (int) $user['id'];
$role    = (string) $user['role'];
$id      = (int) ($_GET['id'] ?? 0);

if ($id <= 0) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

$claim = q_one(
    'SELECT claim_tickets.*,
            accounts.full_name  AS claimant_name,
            accounts.email      AS claimant_email,
            accounts.id_number  AS claimant_id_number,
            matches.id          AS match_id,
            matches.score       AS match_score,
            lost_reports.id     AS lost_id,
            lost_reports.ref_number  AS lost_ref,
            lost_reports.category    AS lost_category,
            lost_reports.color       AS lost_color,
            lost_reports.description AS lost_description,
            found_reports.id         AS found_id,
            found_reports.ref_number AS found_ref
       FROM claim_tickets
       JOIN matches      ON matches.id      = claim_tickets.match_id
       JOIN lost_reports ON lost_reports.id = matches.lost_report_id
       JOIN accounts     ON accounts.id     = claim_tickets.claimant_account_id
       JOIN found_reports ON found_reports.id = matches.found_report_id
      WHERE claim_tickets.id = ? LIMIT 1',
    [$id]
);

if ($claim === null) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

// Users can only see their own claims; staff/admin see all.
$is_owner = (int) $claim['claimant_account_id'] === $user_id;
if (!$is_owner && $role !== 'staff' && $role !== 'admin') {
    http_response_code(403);
    require __DIR__ . '/403.php';
    exit;
}

$id_proofs = q_all(
    "SELECT * FROM attachments
      WHERE attachable_type = 'claim_ticket' AND attachable_id = ? AND purpose = 'id_proof'
      ORDER BY id",
    [$id]
);

$release_log = q_one(
    'SELECT release_logs.*, accounts.full_name AS released_by_name
       FROM release_logs
       JOIN accounts ON accounts.id = release_logs.released_by_account_id
      WHERE release_logs.claim_id = ? LIMIT 1',
    [$id]
);

$can_submit = $is_owner && $claim['status'] === 'pending_user_action';

// ---------------------------------------------------------------------------
// Render
// ---------------------------------------------------------------------------
layout_open('Claim ' . $claim['ref_number']);

$success = flash_get('success');
if ($success) {
    echo '<div class="alert alert-success" role="status">' . e($success) . '</div>';
}

breadcrumb([
    ['Dashboard', url('/index.php?p=dashboard')],
    ['My Claims',  url('/index.php?p=claims')],
    [$claim['ref_number']],
]);

$actions = '';
if ($can_submit) {
    $actions = '<a class="btn btn-primary" href="'
        . e(url('/index.php?p=claim.new&claim=' . $id))
        . '">Upload ID &amp; submit claim</a>';
}

page_header($claim['ref_number'], $actions);
?>

<div class="aside-grid">

  <div>

    <!-- Status card -->
    <section class="card" aria-labelledby="status-title">
      <div class="card-header">
        <h2 class="card-title" id="status-title">What happens next</h2>
        <?= status_badge((string) $claim['status']) ?>
      </div>

      <?php if ($claim['status'] === 'pending_user_action'): ?>
        <p>
          Staff have approved the match for your lost item.
          <strong>Upload a photo of your school ID or government ID</strong> to proceed.
          Once submitted, visit the library counter to collect your item.
        </p>
        <?php if ($can_submit): ?>
          <a class="btn btn-primary mt-4" href="<?= e(url('/index.php?p=claim.new&claim=' . $id)) ?>">
            Upload ID &amp; submit claim
          </a>
        <?php endif; ?>

      <?php elseif ($claim['status'] === 'pending_verification'): ?>
        <p>
          Your ID has been received. Visit the
          <strong>Library Lost &amp; Found counter</strong>
          (Mon&ndash;Sat, 8am&ndash;5pm) with your reference number
          <strong><?= e((string) $claim['ref_number']) ?></strong>.
          Staff will verify your identity and hand over the item.
        </p>

      <?php elseif ($claim['status'] === 'released'): ?>
        <p>Your item has been released. If you have any concerns, contact the library directly.</p>

      <?php elseif ($claim['status'] === 'rejected'): ?>
        <p class="text-muted">
          This claim was rejected. Please contact the library counter for more information.
        </p>
      <?php endif; ?>
    </section>

    <!-- Item details -->
    <section class="card" aria-labelledby="item-title">
      <h2 class="card-title" id="item-title">Item details</h2>
      <dl class="detail-grid">
        <dt>Your report</dt>
        <dd>
          <a href="<?= e(url('/index.php?p=lost.show&id=' . (int) $claim['lost_id'])) ?>">
            <?= e((string) $claim['lost_ref']) ?>
          </a>
        </dd>

        <dt>Category</dt>
        <dd><?= e(category_label((string) $claim['lost_category'])) ?></dd>

        <dt>Color</dt>
        <dd><?= e((string) $claim['lost_color']) ?></dd>

        <dt>Description</dt>
        <dd style="white-space: pre-wrap;"><?= e(mb_strimwidth((string) $claim['lost_description'], 0, 300, '…')) ?></dd>

        <dt>Match score</dt>
        <dd><?= score_chip((int) $claim['match_score']) ?></dd>
      </dl>
    </section>

    <!-- ID proofs -->
    <?php if ($id_proofs): ?>
      <section class="card" aria-labelledby="id-title">
        <h2 class="card-title" id="id-title">Uploaded identity proof</h2>
        <div style="display: flex; gap: var(--space-3); flex-wrap: wrap;">
          <?php foreach ($id_proofs as $proof): ?>
            <img src="<?= e(upload_url($proof)) ?>"
                 alt="ID proof"
                 style="max-width: 240px; max-height: 200px; border-radius: var(--radius-md); border: 1px solid var(--color-border);">
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>

    <!-- Release record -->
    <?php if ($release_log): ?>
      <section class="card" aria-labelledby="release-title">
        <h2 class="card-title" id="release-title">Release record</h2>
        <dl class="detail-grid">
          <dt>Released by</dt>
          <dd><?= e((string) $release_log['released_by_name']) ?></dd>

          <dt>Released at</dt>
          <dd><?= e(date('F j, Y g:i A', strtotime((string) $release_log['released_at']))) ?></dd>

          <?php if (!empty($release_log['notes'])): ?>
            <dt>Notes</dt>
            <dd><?= e((string) $release_log['notes']) ?></dd>
          <?php endif; ?>
        </dl>
      </section>
    <?php endif; ?>

  </div>

  <!-- Sidebar -->
  <aside class="card">
    <h2 class="card-title">Claim info</h2>
    <dl class="detail-grid" style="grid-template-columns: 1fr; row-gap: var(--space-2);">
      <dt>Reference</dt>
      <dd style="font-family: var(--font-family-mono);"><?= e((string) $claim['ref_number']) ?></dd>

      <dt>Opened</dt>
      <dd><?= e(time_ago((string) $claim['created_at'])) ?></dd>

      <?php if ($claim['submitted_at']): ?>
        <dt>ID submitted</dt>
        <dd><?= e(time_ago((string) $claim['submitted_at'])) ?></dd>
      <?php endif; ?>

      <?php if ($role === 'staff' || $role === 'admin'): ?>
        <dt>Claimant</dt>
        <dd>
          <?= e((string) $claim['claimant_name']) ?>
          <div class="text-sm text-muted"><?= e((string) $claim['claimant_id_number']) ?></div>
        </dd>
      <?php endif; ?>
    </dl>

    <?php if (in_array($role, ['staff', 'admin'], true) && in_array($claim['status'], ['pending_user_action', 'pending_verification'], true)): ?>
      <a class="btn btn-primary btn-sm mt-4" href="<?= e(url('/index.php?p=release&claim=' . $id)) ?>">
        Go to release desk
      </a>
    <?php endif; ?>
  </aside>

</div>

<?php
layout_close();
