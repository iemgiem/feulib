<?php
declare(strict_types=1);

/**
 * Claim release workflow — staff/admin.
 *
 * GET  ?p=release&claim=N   Show claim detail + release form (if pending_verification).
 * POST ?p=release&claim=N   Submit release (requires id_proof already on ticket).
 *
 * For claims still in pending_user_action (user hasn't uploaded their ID yet),
 * we show a read-only view with a prompt to wait for the user.
 *
 * For pending_verification claims, staff can complete the release:
 *   - upload a signature (required)
 *   - upload a selfie with the claimant (required)
 *   - record notes (optional)
 *   - inserts release_log, updates claim_ticket + reports to 'released'
 *   - notifies the claimant
 */

$user    = current_user();
$user_id = (int) $user['id'];
$claim_id = (int) ($_GET['claim'] ?? $_POST['claim'] ?? 0);

if ($claim_id <= 0) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

// ---------------------------------------------------------------------------
// Load claim with joined data
// ---------------------------------------------------------------------------
function _load_claim(int $claim_id): ?array
{
    return q_one(
        'SELECT claim_tickets.*,
                accounts.full_name    AS claimant_name,
                accounts.email        AS claimant_email,
                accounts.id_number    AS claimant_id_number,
                matches.id            AS match_id,
                matches.score         AS match_score,
                lost_reports.id       AS lost_id,
                lost_reports.ref_number  AS lost_ref,
                lost_reports.category    AS lost_category,
                lost_reports.color       AS lost_color,
                lost_reports.description AS lost_description,
                found_reports.id         AS found_id,
                found_reports.ref_number AS found_ref,
                found_reports.storage_location_id AS found_storage_id
           FROM claim_tickets
           JOIN matches      ON matches.id      = claim_tickets.match_id
           JOIN lost_reports ON lost_reports.id = matches.lost_report_id
           JOIN accounts     ON accounts.id     = claim_tickets.claimant_account_id
           JOIN found_reports ON found_reports.id = matches.found_report_id
          WHERE claim_tickets.id = ? LIMIT 1',
        [$claim_id]
    );
}

$claim = _load_claim($claim_id);
if ($claim === null) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

$storage = q_one(
    'SELECT code, description FROM storage_locations WHERE id = ?',
    [(int) $claim['found_storage_id']]
);

$id_proofs = q_all(
    "SELECT * FROM attachments
      WHERE attachable_type = 'claim_ticket' AND attachable_id = ? AND purpose = 'id_proof'
      ORDER BY id",
    [$claim_id]
);

$is_verifiable = $claim['status'] === 'pending_verification';
$is_done       = in_array($claim['status'], ['released', 'rejected'], true);

$release_log = $is_done
    ? q_one('SELECT * FROM release_logs WHERE claim_id = ? LIMIT 1', [$claim_id])
    : null;

// ---------------------------------------------------------------------------
// POST — complete the release
// ---------------------------------------------------------------------------
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_verifiable) {
    csrf_check();

    $notes = trim($_POST['notes'] ?? '');

    // Validate signature upload
    $sig_file = $_FILES['signature'] ?? null;
    if (empty($sig_file['tmp_name'])) {
        $errors['signature'] = ['Signature photo is required.'];
    }

    // Validate selfie upload
    $selfie_file = $_FILES['selfie'] ?? null;
    if (empty($selfie_file['tmp_name'])) {
        $errors['selfie'] = ['Selfie with claimant is required.'];
    }

    if (!$errors) {
        try {
            $sig_id    = null;
            $selfie_id = null;

            db_transaction(function () use (
                $claim_id, $claim, $user_id, $notes, $sig_file, $selfie_file,
                &$sig_id, &$selfie_id
            ) {
                // Store signature attachment
                $sig_id = upload_store($sig_file, 'claim_ticket', $claim_id, 'signature');

                // Store selfie attachment
                $selfie_id = upload_store($selfie_file, 'claim_ticket', $claim_id, 'selfie');

                // Create release log
                q(
                    'INSERT INTO release_logs
                        (claim_id, released_by_account_id, released_to_account_id,
                         signature_attachment_id, selfie_attachment_id, notes, released_at)
                     VALUES (?, ?, ?, ?, ?, ?, NOW())',
                    [
                        $claim_id,
                        $user_id,
                        (int) $claim['claimant_account_id'],
                        $sig_id,
                        $selfie_id,
                        $notes !== '' ? $notes : null,
                    ]
                );

                // Update claim ticket
                q(
                    "UPDATE claim_tickets SET status = 'released', updated_at = NOW() WHERE id = ?",
                    [$claim_id]
                );
                audit_log('claim.release', 'claim_ticket', $claim_id, [
                    'status' => ['pending_verification', 'released'],
                ]);

                // Update found + lost reports
                q("UPDATE found_reports SET status = 'released', updated_at = NOW() WHERE id = ?",
                    [(int) $claim['found_id']]);
                q("UPDATE lost_reports  SET status = 'released', updated_at = NOW() WHERE id = ?",
                    [(int) $claim['lost_id']]);

                // Notify claimant
                q(
                    'INSERT INTO notifications
                        (recipient_account_id, type, title, body, link_url, created_at)
                     VALUES (?, ?, ?, ?, ?, NOW())',
                    [
                        (int) $claim['claimant_account_id'],
                        'claim.released',
                        'Your item has been released',
                        'Your ' . category_label((string) $claim['lost_category'])
                            . ' (' . $claim['lost_ref'] . ') has been released to you.',
                        '/index.php?p=claim.show&id=' . $claim_id,
                    ]
                );
            });

            flash_set('success', 'Item released. The claimant has been notified.');
            go(url('/index.php?p=release&claim=' . $claim_id));
        } catch (\RuntimeException $e) {
            $errors['signature'] = [$e->getMessage()];
        } catch (\Throwable $e) {
            $errors[] = 'A database error occurred. Please try again.';
        }
    }

    $claim        = _load_claim($claim_id) ?? $claim;
    $is_verifiable = $claim['status'] === 'pending_verification';
    $is_done       = in_array($claim['status'], ['released', 'rejected'], true);
    $release_log   = $is_done
        ? q_one('SELECT * FROM release_logs WHERE claim_id = ? LIMIT 1', [$claim_id])
        : null;
}

// ---------------------------------------------------------------------------
// Render
// ---------------------------------------------------------------------------
layout_open('Claim ' . $claim['ref_number']);

$success = flash_get('success');
if ($success) {
    echo '<div class="alert alert-success" role="status">' . e($success) . '</div>';
}

if (!empty($errors)) {
    echo '<div class="alert alert-error" role="alert"><ul>';
    foreach ((array) $errors as $field_errors) {
        foreach ((array) $field_errors as $err) {
            echo '<li>' . e($err) . '</li>';
        }
    }
    echo '</ul></div>';
}

breadcrumb([
    ['Dashboard',   url('/index.php?p=staff.dashboard')],
    ['All Claims',  url('/index.php?p=staff.claims')],
    [$claim['ref_number']],
]);

page_header($claim['ref_number'], status_badge((string) $claim['status']));
?>

<div style="display: grid; grid-template-columns: 1fr 300px; gap: var(--card-gap); align-items: start;">

  <div>

    <!-- Claimant + item summary -->
    <section class="card" aria-labelledby="claim-summary">
      <h2 class="card-title" id="claim-summary">Claim summary</h2>
      <dl class="detail-grid">
        <dt>Claimant</dt>
        <dd>
          <?= e((string) $claim['claimant_name']) ?>
          <div class="text-sm text-muted"><?= e((string) $claim['claimant_id_number']) ?></div>
        </dd>

        <dt>Lost item</dt>
        <dd>
          <a href="<?= e(url('/index.php?p=lost.show&id=' . (int) $claim['lost_id'])) ?>">
            <?= e((string) $claim['lost_ref']) ?>
          </a>
          <div class="text-sm text-muted">
            <?= e(category_label((string) $claim['lost_category'])) ?> &middot; <?= e((string) $claim['lost_color']) ?>
          </div>
        </dd>

        <dt>Found item</dt>
        <dd>
          <a href="<?= e(url('/index.php?p=found.show&id=' . (int) $claim['found_id'])) ?>">
            <?= e((string) $claim['found_ref']) ?>
          </a>
          <?php if ($storage): ?>
            <div class="text-sm text-muted">
              Storage: <?= e((string) $storage['code']) ?>
            </div>
          <?php endif; ?>
        </dd>

        <dt>Match score</dt>
        <dd><?= score_chip((int) $claim['match_score']) ?></dd>

        <dt>Description</dt>
        <dd style="white-space: pre-wrap;"><?= e(mb_strimwidth((string) $claim['lost_description'], 0, 300, '…')) ?></dd>
      </dl>
    </section>

    <!-- ID proofs uploaded by claimant -->
    <?php if ($id_proofs): ?>
      <section class="card" aria-labelledby="id-proofs-title">
        <h2 class="card-title" id="id-proofs-title">Identity proof</h2>
        <div style="display: flex; gap: var(--space-3); flex-wrap: wrap;">
          <?php foreach ($id_proofs as $proof): ?>
            <div>
              <img src="<?= e(upload_url($proof)) ?>"
                   alt="ID proof uploaded by claimant"
                   style="max-width: 240px; max-height: 200px; border-radius: var(--radius-md); border: 1px solid var(--color-border);">
            </div>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>

    <!-- Release form -->
    <?php if ($is_verifiable): ?>
      <section class="card" aria-labelledby="release-form-title">
        <h2 class="card-title" id="release-form-title">Complete release</h2>
        <p class="card-subtitle">
          Verify the claimant's identity in person, then capture a signature and selfie
          before handing over the item.
        </p>

        <form method="POST" enctype="multipart/form-data" class="stack-4" id="release-form">
          <?= csrf_field() ?>
          <input type="hidden" name="claim" value="<?= e((string) $claim_id) ?>">

          <div class="form-group">
            <label for="signature" class="form-label form-label-required">Claimant signature</label>
            <?= field_error_html('signature', $errors) ?>
            <input type="file" id="signature" name="signature" accept="image/*"
                   class="form-control<?= !empty($errors['signature']) ? ' is-invalid' : '' ?>"<?= field_aria('signature', $errors) ?>>
            <span class="form-hint">Upload a photo of the signed release form or use a signature-capture app.</span>
          </div>

          <div class="form-group">
            <label for="selfie" class="form-label form-label-required">Selfie with claimant</label>
            <?= field_error_html('selfie', $errors) ?>
            <input type="file" id="selfie" name="selfie" accept="image/*"
                   class="form-control<?= !empty($errors['selfie']) ? ' is-invalid' : '' ?>"<?= field_aria('selfie', $errors) ?>>
            <span class="form-hint">Take a photo of the claimant holding the released item.</span>
          </div>

          <div class="form-group">
            <label for="notes" class="form-label">Notes <span class="form-hint">(optional)</span></label>
            <textarea id="notes" name="notes" rows="3" class="form-control"
                      placeholder="Any remarks about the release…"><?= e($_POST['notes'] ?? '') ?></textarea>
          </div>

          <button type="button" class="btn btn-primary"
                  data-modal-open="release-confirm-modal">
            Confirm release
          </button>

          <!-- Hold-to-confirm modal (Task 27) — final guard before the release
               is recorded. The Confirm button must be held 1.5 s; on completion
               modal.js calls form.requestSubmit() which fires native validation
               on the signature + selfie inputs above. -->
          <?php
            modal_open('release-confirm-modal', 'Confirm item release', [
                'role'        => 'alertdialog',
                'describedby' => 'release-confirm-desc',
            ]);
          ?>
            <p id="release-confirm-desc">
              You are about to release this item to
              <strong><?= e((string) $claim['claimant_name']) ?></strong>.
              A signature and selfie will be saved permanently, and the
              reports will move to <strong>Released</strong>.
              This action cannot be undone.
            </p>
            <dl class="detail-grid text-sm">
              <dt>Claim</dt>
              <dd><?= e((string) $claim['ref_number']) ?></dd>
              <dt>Lost report</dt>
              <dd><?= e((string) $claim['lost_ref']) ?></dd>
              <dt>Found item</dt>
              <dd><?= e((string) $claim['found_ref']) ?></dd>
            </dl>
            <p class="text-sm text-muted">
              Hold the confirm button for 1.5&nbsp;seconds to prevent accidental release.
            </p>
          <?php
            modal_footer_open();
          ?>
            <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
            <button type="button" class="btn btn-primary btn-hold" data-modal-hold="1500">
              <span class="modal-hold-progress" aria-hidden="true"></span>
              Hold to release
            </button>
          <?php
            modal_footer_close();
            modal_close();
          ?>
        </form>
      </section>
    <?php elseif ($claim['status'] === 'pending_user_action'): ?>
      <div class="card">
        <p class="text-muted">
          Waiting for <strong><?= e((string) $claim['claimant_name']) ?></strong> to
          upload their identity proof. Once they submit, the status will change to
          <strong>Pending verification</strong> and you can complete the release.
        </p>
      </div>
    <?php endif; ?>

    <!-- Release log (post-release) -->
    <?php if ($release_log): ?>
      <section class="card" aria-labelledby="release-log-title">
        <h2 class="card-title" id="release-log-title">Release record</h2>
        <?php
          $rel_by = q_one('SELECT full_name FROM accounts WHERE id = ?',
              [(int) $release_log['released_by_account_id']]);
          $rel_sig   = q_one('SELECT * FROM attachments WHERE id = ?',
              [(int) $release_log['signature_attachment_id']]);
          $rel_selfie = q_one('SELECT * FROM attachments WHERE id = ?',
              [(int) $release_log['selfie_attachment_id']]);
        ?>
        <dl class="detail-grid">
          <dt>Released by</dt>
          <dd><?= e((string) ($rel_by['full_name'] ?? '—')) ?></dd>

          <dt>Released at</dt>
          <dd><?= e(date('F j, Y g:i A', strtotime((string) $release_log['released_at']))) ?></dd>

          <?php if (!empty($release_log['notes'])): ?>
            <dt>Notes</dt>
            <dd><?= e((string) $release_log['notes']) ?></dd>
          <?php endif; ?>
        </dl>

        <div style="display: flex; gap: var(--space-3); flex-wrap: wrap; margin-top: var(--space-4);">
          <?php if ($rel_sig): ?>
            <div>
              <p class="text-sm text-muted mb-1">Signature</p>
              <img src="<?= e(upload_url($rel_sig)) ?>" alt="Release signature"
                   style="max-width: 200px; max-height: 160px; border-radius: var(--radius-md); border: 1px solid var(--color-border);">
            </div>
          <?php endif; ?>
          <?php if ($rel_selfie): ?>
            <div>
              <p class="text-sm text-muted mb-1">Selfie</p>
              <img src="<?= e(upload_url($rel_selfie)) ?>" alt="Claimant selfie"
                   style="max-width: 200px; max-height: 160px; border-radius: var(--radius-md); border: 1px solid var(--color-border);">
            </div>
          <?php endif; ?>
        </div>
      </section>
    <?php endif; ?>

  </div>

  <!-- Sidebar -->
  <aside class="card">
    <h2 class="card-title">Claim status</h2>
    <p class="card-subtitle" style="margin-bottom: var(--space-3);">
      <?php if ($claim['status'] === 'pending_user_action'): ?>
        Waiting for the claimant to upload their identity proof.
      <?php elseif ($claim['status'] === 'pending_verification'): ?>
        Identity proof uploaded. Ready for counter verification.
      <?php elseif ($claim['status'] === 'released'): ?>
        Item has been released to the claimant.
      <?php elseif ($claim['status'] === 'rejected'): ?>
        Claim was rejected.
      <?php endif; ?>
    </p>

    <dl class="detail-grid" style="grid-template-columns: 1fr; row-gap: var(--space-2);">
      <dt>Claim ref</dt>
      <dd style="font-family: var(--font-family-mono);"><?= e((string) $claim['ref_number']) ?></dd>

      <dt>Created</dt>
      <dd><?= e(time_ago((string) $claim['created_at'])) ?></dd>

      <?php if ($claim['submitted_at']): ?>
        <dt>ID uploaded</dt>
        <dd><?= e(time_ago((string) $claim['submitted_at'])) ?></dd>
      <?php endif; ?>
    </dl>
  </aside>

</div>

<?php
layout_close();
