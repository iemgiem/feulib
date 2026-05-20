<?php
declare(strict_types=1);

/**
 * Match detail + review — staff/admin.
 *
 * Shows the lost report and found item side-by-side with the score breakdown.
 * Staff can approve, reject, or mark needs_info (with notes).
 *
 * On approve:
 *   - match → approved
 *   - lost_report + found_report → matched
 *   - claim_ticket created (pending_user_action)
 *   - notification sent to the reporter
 *
 * On reject:
 *   - match → rejected (reports remain open for future matches)
 *
 * On needs_info:
 *   - match → needs_info (notes required)
 */

$user    = current_user();
$user_id = (int) $user['id'];
$id      = (int) ($_GET['id'] ?? 0);

if ($id <= 0) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

// ---------------------------------------------------------------------------
// Load match (with joined report fields)
// ---------------------------------------------------------------------------
function _load_match(int $id): ?array
{
    return q_one(
        'SELECT matches.*,
                lost_reports.id              AS lost_id,
                lost_reports.ref_number      AS lost_ref,
                lost_reports.category        AS lost_category,
                lost_reports.color           AS lost_color,
                lost_reports.brand           AS lost_brand,
                lost_reports.description     AS lost_description,
                lost_reports.last_seen_location AS lost_location,
                lost_reports.date_lost       AS lost_date,
                lost_reports.status          AS lost_status,
                lost_reports.reporter_account_id AS lost_reporter_id,
                found_reports.id             AS found_id,
                found_reports.ref_number     AS found_ref,
                found_reports.category       AS found_category,
                found_reports.color          AS found_color,
                found_reports.brand          AS found_brand,
                found_reports.description    AS found_description,
                found_reports.date_found     AS found_date,
                found_reports.status         AS found_status,
                found_reports.storage_location_id AS found_storage_id
           FROM matches
           JOIN lost_reports  ON lost_reports.id  = matches.lost_report_id
           JOIN found_reports ON found_reports.id = matches.found_report_id
          WHERE matches.id = ? LIMIT 1',
        [$id]
    );
}

$match = _load_match($id);
if ($match === null) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

$is_actionable = in_array($match['status'], ['pending', 'needs_info'], true);

// ---------------------------------------------------------------------------
// POST handler
// ---------------------------------------------------------------------------
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action = trim($_POST['action'] ?? '');
    $notes  = trim($_POST['review_notes'] ?? '');

    if (!in_array($action, ['approve', 'reject', 'needs_info'], true)) {
        $errors[] = 'Invalid action.';
    } elseif (!$is_actionable) {
        $errors[] = 'This match has already been reviewed and cannot be changed.';
    }
    if ($action === 'needs_info' && $notes === '') {
        $errors[] = 'Please describe what additional information is needed.';
    }
    if ($action === 'reject' && $notes === '') {
        $errors[] = 'Please provide a reason for rejecting this match.';
    }

    if (!$errors) {
        $new_status = match ($action) {
            'approve'    => 'approved',
            'reject'     => 'rejected',
            'needs_info' => 'needs_info',
        };

        try {
            db_transaction(function () use ($action, $new_status, $notes, $id, $match, $user_id) {
                q(
                    'UPDATE matches
                        SET status = ?, reviewed_by_account_id = ?, reviewed_at = NOW(),
                            review_notes = ?, updated_at = NOW()
                      WHERE id = ?',
                    [$new_status, $user_id, $notes !== '' ? $notes : null, $id]
                );
                audit_log("match.{$action}", 'match', $id, [
                    'status' => [$match['status'], $new_status],
                ]);

                if ($action === 'approve') {
                    q("UPDATE lost_reports  SET status = 'matched', updated_at = NOW() WHERE id = ? AND status = 'open'",
                        [(int) $match['lost_report_id']]);
                    q("UPDATE found_reports SET status = 'matched', updated_at = NOW() WHERE id = ? AND status = 'open'",
                        [(int) $match['found_report_id']]);

                    // INSERT IGNORE lets the DB-level UNIQUE KEY uq_claim_match(match_id)
                    // absorb a concurrent second approve without throwing. We use a
                    // placeholder ref_number (updated below) because the real value
                    // requires the auto-increment id.
                    q(
                        "INSERT IGNORE INTO claim_tickets
                             (match_id, claimant_account_id, ref_number, status, created_at, updated_at)
                          VALUES (?, ?, '', ?, NOW(), NOW())",
                        [(int) $match['id'], (int) $match['lost_reporter_id'], 'pending_user_action']
                    );
                    $claim_id = db_last_id();

                    if ($claim_id) {
                        // This request won the race — set the real ref_number, audit, notify.
                        $claim_ref = make_ref_number('claim', $claim_id);
                        q('UPDATE claim_tickets SET ref_number = ? WHERE id = ?', [$claim_ref, $claim_id]);
                        audit_log('claim.create', 'claim_ticket', $claim_id, [
                            'status' => [null, 'pending_user_action'],
                        ]);

                        q(
                            'INSERT INTO notifications
                                (recipient_account_id, type, title, body, link_url, created_at)
                             VALUES (?, ?, ?, ?, ?, NOW())',
                            [
                                (int) $match['lost_reporter_id'],
                                'match.approved',
                                'Match approved — please submit your claim',
                                'Staff approved a match for your ' . category_label((string) $match['lost_category'])
                                    . '. Click to view your claim and upload your ID.',
                                '/index.php?p=claim.show&id=' . $claim_id,
                            ]
                        );
                    }
                    // If $claim_id === 0, a concurrent approve already created the claim;
                    // the unique constraint silently ignored this insert — no action needed.
                }
            });

            flash_set('success', match ($action) {
                'approve'    => 'Match approved. A claim ticket has been created and the reporter notified.',
                'reject'     => 'Match rejected.',
                'needs_info' => 'Match marked as needs info.',
            });
            go(url('/index.php?p=match.show&id=' . $id));
        } catch (\Throwable $e) {
            $errors[] = 'A database error occurred. Please try again.';
        }
    }

    // Re-read after any successful update
    $match = _load_match($id) ?? $match;
    $is_actionable = in_array($match['status'], ['pending', 'needs_info'], true);
}

// ---------------------------------------------------------------------------
// Supporting data
// ---------------------------------------------------------------------------
$reporter = q_one(
    'SELECT id, full_name, email, id_number FROM accounts WHERE id = ?',
    [(int) $match['lost_reporter_id']]
);

$storage = q_one(
    'SELECT code, description FROM storage_locations WHERE id = ?',
    [(int) $match['found_storage_id']]
);

$lost_photo = q_one(
    "SELECT * FROM attachments
      WHERE attachable_type = 'lost_report' AND attachable_id = ? AND purpose = 'photo'
      ORDER BY id LIMIT 1",
    [(int) $match['lost_report_id']]
);

$found_photo = q_one(
    "SELECT * FROM attachments
      WHERE attachable_type = 'found_report' AND attachable_id = ? AND purpose = 'photo'
      ORDER BY id LIMIT 1",
    [(int) $match['found_report_id']]
);

$factors = json_decode((string) $match['factors_json'], true) ?? [];

$reviewer = null;
if (!empty($match['reviewed_by_account_id'])) {
    $reviewer = q_one(
        'SELECT full_name FROM accounts WHERE id = ?',
        [(int) $match['reviewed_by_account_id']]
    );
}

// ---------------------------------------------------------------------------
// Render
// ---------------------------------------------------------------------------
layout_open('Match ' . $match['lost_ref'] . ' / ' . $match['found_ref']);

$success = flash_get('success');
if ($success) {
    echo '<div class="alert alert-success" role="status">' . e($success) . '</div>';
}

if ($errors) {
    echo '<div class="alert alert-error" role="alert"><ul>';
    foreach ($errors as $err) {
        echo '<li>' . e($err) . '</li>';
    }
    echo '</ul></div>';
}

breadcrumb([
    ['Dashboard',   url('/index.php?p=staff.dashboard')],
    ['All Matches', url('/index.php?p=matches')],
    ['Match #' . $id],
]);

page_header(
    'Match #' . $id . ' — ' . score_chip((int) $match['score']),
    status_badge((string) $match['status'])
);
?>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--card-gap); align-items: start;">

  <!-- Lost Report -->
  <section class="card" aria-labelledby="lost-title">
    <div class="card-header">
      <h2 class="card-title" id="lost-title">
        <a href="<?= e(url('/index.php?p=lost.show&id=' . (int) $match['lost_id'])) ?>">
          <?= e((string) $match['lost_ref']) ?>
        </a>
      </h2>
      <?= status_badge((string) $match['lost_status']) ?>
    </div>

    <?php if ($lost_photo): ?>
      <div class="detail-photo">
        <img src="<?= e(upload_url($lost_photo)) ?>"
             alt="<?= e(category_label((string) $match['lost_category']) . ', ' . (string) $match['lost_color']) ?>">
      </div>
    <?php endif; ?>

    <dl class="detail-grid">
      <dt>Category</dt>
      <dd><?= e(category_label((string) $match['lost_category'])) ?></dd>

      <dt>Color</dt>
      <dd><?= e((string) $match['lost_color']) ?></dd>

      <?php if (!empty($match['lost_brand'])): ?>
        <dt>Brand / marks</dt>
        <dd><?= e((string) $match['lost_brand']) ?></dd>
      <?php endif; ?>

      <dt>Description</dt>
      <dd style="white-space: pre-wrap;"><?= e((string) $match['lost_description']) ?></dd>

      <dt>Last seen</dt>
      <dd><?= e((string) $match['lost_location']) ?></dd>

      <dt>Date lost</dt>
      <dd><?= e(date('F j, Y', strtotime((string) $match['lost_date']))) ?></dd>

      <dt>Reporter</dt>
      <dd>
        <?= e((string) ($reporter['full_name'] ?? '—')) ?>
        <?php if (!empty($reporter['id_number'])): ?>
          <div class="text-sm text-muted"><?= e((string) $reporter['id_number']) ?></div>
        <?php endif; ?>
      </dd>
    </dl>
  </section>

  <!-- Found Report -->
  <section class="card" aria-labelledby="found-title">
    <div class="card-header">
      <h2 class="card-title" id="found-title">
        <a href="<?= e(url('/index.php?p=found.show&id=' . (int) $match['found_id'])) ?>">
          <?= e((string) $match['found_ref']) ?>
        </a>
      </h2>
      <?= status_badge((string) $match['found_status']) ?>
    </div>

    <?php if ($found_photo): ?>
      <div class="detail-photo">
        <img src="<?= e(upload_url($found_photo)) ?>"
             alt="<?= e(category_label((string) $match['found_category']) . ', ' . (string) $match['found_color']) ?>">
      </div>
    <?php endif; ?>

    <dl class="detail-grid">
      <dt>Category</dt>
      <dd><?= e(category_label((string) $match['found_category'])) ?></dd>

      <dt>Color</dt>
      <dd><?= e((string) $match['found_color']) ?></dd>

      <?php if (!empty($match['found_brand'])): ?>
        <dt>Brand / marks</dt>
        <dd><?= e((string) $match['found_brand']) ?></dd>
      <?php endif; ?>

      <dt>Description</dt>
      <dd style="white-space: pre-wrap;"><?= e((string) $match['found_description']) ?></dd>

      <dt>Storage</dt>
      <dd>
        <strong><?= e((string) ($storage['code'] ?? '—')) ?></strong>
        <?php if (!empty($storage['description'])): ?>
          <div class="text-sm text-muted"><?= e((string) $storage['description']) ?></div>
        <?php endif; ?>
      </dd>

      <dt>Date found</dt>
      <dd><?= e(date('F j, Y', strtotime((string) $match['found_date']))) ?></dd>
    </dl>
  </section>

</div>

<!-- Score breakdown -->
<?php if ($factors): ?>
  <section class="card" aria-labelledby="score-title">
    <h2 class="card-title" id="score-title">Score breakdown</h2>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr><th>Factor</th><th class="col-narrow">Points</th></tr>
        </thead>
        <tbody>
          <?php foreach ($factors as $factor => $points): ?>
            <tr>
              <td><?= e(ucfirst((string) $factor)) ?></td>
              <td class="col-narrow"><?= e((string) $points) ?></td>
            </tr>
          <?php endforeach; ?>
          <tr style="font-weight: 600;">
            <td>Total</td>
            <td class="col-narrow"><?= e((string) $match['score']) ?></td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
<?php endif; ?>

<?php if ($reviewer || !empty($match['review_notes'])): ?>
  <section class="card">
    <h2 class="card-title">Review details</h2>
    <dl class="detail-grid">
      <?php if ($reviewer): ?>
        <dt>Reviewed by</dt>
        <dd><?= e((string) $reviewer['full_name']) ?></dd>
      <?php endif; ?>
      <?php if (!empty($match['reviewed_at'])): ?>
        <dt>Reviewed at</dt>
        <dd><?= e(time_ago((string) $match['reviewed_at'])) ?></dd>
      <?php endif; ?>
      <?php if (!empty($match['review_notes'])): ?>
        <dt>Notes</dt>
        <dd style="white-space: pre-wrap;"><?= e((string) $match['review_notes']) ?></dd>
      <?php endif; ?>
    </dl>
  </section>
<?php endif; ?>

<!-- Review form (only for actionable matches) -->
<?php if ($is_actionable): ?>
  <section class="card" aria-labelledby="review-title">
    <h2 class="card-title" id="review-title">Staff review</h2>

    <?php if ($match['is_suspicious']): ?>
      <div class="alert alert-error" role="alert">
        <strong>Suspicious flag:</strong> the reporter has filed another lost report
        with the same category and colour in the last 24 hours.
      </div>
    <?php endif; ?>

    <form method="POST" class="stack-4">
      <?= csrf_field() ?>

      <div class="form-group">
        <label for="review_notes" class="form-label">Notes
          <span class="form-hint">(required when marking "Needs info")</span>
        </label>
        <textarea id="review_notes" name="review_notes" rows="4" class="form-control"
                  placeholder="Explain any discrepancies, request additional photos, or leave a note for the file."><?= e($_POST['review_notes'] ?? (string) $match['review_notes']) ?></textarea>
      </div>

      <div style="display: flex; gap: var(--space-3); flex-wrap: wrap;">
        <button type="submit" name="action" value="approve" class="btn btn-primary">
          Approve match
        </button>
        <button type="submit" name="action" value="needs_info" class="btn btn-ghost"
                onclick="return document.getElementById('review_notes').value.trim() !== '' || (alert('Please enter notes before marking as Needs Info.'), false)">
          Needs info
        </button>
        <button type="button" class="btn btn-danger"
                data-modal-open="reject-match-modal">
          Reject match
        </button>
      </div>
    </form>

    <!-- Reject confirmation modal (Task 27) — captures a required reason
         before submitting. Lives outside the main review form so the
         reason field doesn't clash with the inline review_notes textarea. -->
    <form method="POST" id="reject-match-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="reject">
      <?php
        modal_open('reject-match-modal', 'Reject this match?', [
            'role'        => 'alertdialog',
            'size'        => 'sm',
            'describedby' => 'reject-match-desc',
        ]);
      ?>
        <p id="reject-match-desc" class="text-muted">
          The lost and found reports will remain open and may be matched again later.
          Please tell other staff why this pairing was wrong.
        </p>
        <div class="form-group">
          <label for="reject_reason" class="form-label form-label-required">
            Reason for rejection
          </label>
          <textarea id="reject_reason" name="review_notes" rows="4" required
                    class="form-control"
                    placeholder="e.g. Different brand and the colour photos clearly don't match."></textarea>
        </div>
      <?php
        modal_footer_open();
      ?>
        <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-danger">Reject match</button>
      <?php
        modal_footer_close();
        modal_close();
      ?>
    </form>
  </section>
<?php endif; ?>

<?php
layout_close();
