<?php
declare(strict_types=1);

/**
 * Claim submission — user uploads their ID proof against an approved match.
 *
 * GET  ?p=claim.new&match=N   Show the form for the given match.
 * POST ?p=claim.new           Submit the ID proof and advance the claim ticket
 *                              from pending_user_action → pending_verification.
 *
 * The claim ticket is created by the staff approval flow (match.show.php).
 * This page lets the user attach their ID proof and confirm the claim.
 */

$user    = current_user();
$user_id = (int) $user['id'];

// Users can arrive from a notification (which carries claim id) or from a match id.
$claim_id = (int) ($_GET['claim'] ?? $_POST['claim'] ?? 0);
$match_id = (int) ($_GET['match'] ?? 0);

// Look up the claim — either by id or by match id for this user.
$claim = null;
if ($claim_id > 0) {
    $claim = q_one(
        'SELECT * FROM claim_tickets WHERE id = ? AND claimant_account_id = ? LIMIT 1',
        [$claim_id, $user_id]
    );
} elseif ($match_id > 0) {
    $claim = q_one(
        'SELECT * FROM claim_tickets WHERE match_id = ? AND claimant_account_id = ? LIMIT 1',
        [$match_id, $user_id]
    );
}

if ($claim === null) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

$claim_id = (int) $claim['id'];

// Already submitted?
if ($claim['status'] !== 'pending_user_action') {
    go(url('/index.php?p=claim.show&id=' . $claim_id));
}

// Load the match + item details for context.
$details = q_one(
    'SELECT matches.score,
            lost_reports.id          AS lost_id,
            lost_reports.ref_number  AS lost_ref,
            lost_reports.category    AS lost_category,
            lost_reports.color       AS lost_color,
            lost_reports.description AS lost_description,
            found_reports.id         AS found_id,
            found_reports.ref_number AS found_ref
       FROM matches
       JOIN lost_reports  ON lost_reports.id  = matches.lost_report_id
       JOIN found_reports ON found_reports.id = matches.found_report_id
      WHERE matches.id = ? LIMIT 1',
    [(int) $claim['match_id']]
);

if ($details === null) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

// ---------------------------------------------------------------------------
// POST
// ---------------------------------------------------------------------------
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $id_file = $_FILES['id_proof'] ?? null;
    if (empty($id_file['tmp_name'])) {
        $errors['id_proof'] = ['Please upload a photo of your valid ID.'];
    }

    if (!$errors) {
        try {
            db_transaction(function () use ($id_file, $claim_id, $user_id, $claim) {
                upload_store($id_file, 'claim_ticket', $claim_id, 'id_proof');

                q(
                    "UPDATE claim_tickets
                        SET status = 'pending_verification', submitted_at = NOW(), updated_at = NOW()
                      WHERE id = ?",
                    [$claim_id]
                );
                audit_log('claim.submit', 'claim_ticket', $claim_id, [
                    'status' => ['pending_user_action', 'pending_verification'],
                ]);
            });

            flash_set('success', 'Your claim has been submitted. Visit the library counter with your reference number to collect your item.');
            go(url('/index.php?p=claim.show&id=' . $claim_id));
        } catch (\RuntimeException $e) {
            $errors['id_proof'] = [$e->getMessage()];
        } catch (\Throwable $e) {
            $errors[] = 'A database error occurred. Please try again.';
        }
    }
}

// ---------------------------------------------------------------------------
// Render
// ---------------------------------------------------------------------------
layout_open('Submit Claim — ' . $claim['ref_number']);

breadcrumb([
    ['Dashboard', url('/index.php?p=dashboard')],
    ['My Claims',  url('/index.php?p=claims')],
    [$claim['ref_number']],
]);

page_header('Submit your claim');
?>

<div class="aside-grid">

  <section class="card" aria-labelledby="claim-form-title">
    <h2 class="card-title" id="claim-form-title">Upload identity proof</h2>
    <p class="card-subtitle">
      Upload a clear photo of your school ID, certificate of enrollment, or
      government-issued ID. Staff will verify it when you collect the item at
      the library counter.
    </p>

    <?php if (!empty($errors)): ?>
      <div class="alert alert-error" role="alert">
        <ul>
          <?php foreach ($errors as $field_errors): ?>
            <?php foreach ((array) $field_errors as $err): ?>
              <li><?= e($err) ?></li>
            <?php endforeach; ?>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" class="stack-4">
      <?= csrf_field() ?>
      <input type="hidden" name="claim" value="<?= e((string) $claim_id) ?>">

      <div class="form-group">
        <label for="id_proof" class="form-label form-label-required">Valid ID photo</label>
        <?= field_error_html('id_proof', $errors) ?>
        <input type="file" id="id_proof" name="id_proof" accept="image/*"
               class="form-control<?= !empty($errors['id_proof']) ? ' is-invalid' : '' ?>"<?= field_aria('id_proof', $errors) ?>>
        <span class="form-hint">Accepted: JPG, PNG, WebP. Max 4 MB.</span>
      </div>

      <button type="submit" class="btn btn-primary">Submit claim</button>
    </form>
  </section>

  <!-- Item summary -->
  <aside class="card">
    <h2 class="card-title">Matched item</h2>
    <dl class="detail-grid" style="grid-template-columns: 1fr; row-gap: var(--space-2);">
      <dt>Your report</dt>
      <dd>
        <a href="<?= e(url('/index.php?p=lost.show&id=' . (int) $details['lost_id'])) ?>">
          <?= e((string) $details['lost_ref']) ?>
        </a>
      </dd>

      <dt>Item</dt>
      <dd>
        <?= e(category_label((string) $details['lost_category'])) ?>
        <div class="text-sm text-muted"><?= e((string) $details['lost_color']) ?></div>
      </dd>

      <dt>Claim ref</dt>
      <dd style="font-family: var(--font-family-mono);"><?= e((string) $claim['ref_number']) ?></dd>

      <dt>Match score</dt>
      <dd><?= score_chip((int) $details['score']) ?></dd>
    </dl>

    <div class="alert alert-info mt-4" role="note" style="font-size: var(--font-size-sm);">
      After uploading, visit the <strong>Library Lost &amp; Found counter</strong>
      (Mon&ndash;Sat, 8am&ndash;5pm) with your reference number
      <strong><?= e((string) $claim['ref_number']) ?></strong>.
    </div>
  </aside>

</div>

<?php
layout_close();
