<?php
declare(strict_types=1);

/**
 * Admin — donate expired items (Task 22).
 *
 * Lists found items in the EXPIRED state and lets an admin bulk-mark a selection
 * as DONATED, capturing a required partner/beneficiary note via the shared modal
 * (Task 27). Each donation writes an audit entry (found_report.donate). Donation
 * is one-way — there is no un-donate.
 */

$user    = current_user();
$user_id = (int) $user['id'];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $ids = array_values(array_filter(
        array_map('intval', (array) ($_POST['ids'] ?? [])),
        static fn(int $i): bool => $i > 0
    ));
    $beneficiary = trim((string) ($_POST['beneficiary'] ?? ''));

    if (!$ids) {
        $errors[] = 'Select at least one expired item to donate.';
    }
    if ($beneficiary === '') {
        $errors[] = 'A partner / beneficiary note is required.';
    }

    if (!$errors) {
        // Re-verify the selection is genuinely still EXPIRED — guards against a
        // stale page or tampered ids. Only these get donated.
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $valid_ids = array_map('intval', array_column(
            q_all(
                "SELECT id FROM found_reports WHERE status = 'expired' AND id IN ($placeholders)",
                $ids
            ),
            'id'
        ));

        if (!$valid_ids) {
            $errors[] = 'None of the selected items are still awaiting donation.';
        } else {
            db_transaction(function () use ($valid_ids, $beneficiary) {
                foreach ($valid_ids as $id) {
                    q("UPDATE found_reports SET status = 'donated', updated_at = NOW() WHERE id = ?", [$id]);
                    audit_log('found_report.donate', 'found_report', $id, [
                        'status'      => ['expired', 'donated'],
                        'beneficiary' => $beneficiary,
                    ]);
                }
            });
            flash_set('success', count($valid_ids) . ' item' . (count($valid_ids) === 1 ? '' : 's')
                . ' marked as donated.');
            go(url('/index.php?p=admin.donate'));
        }
    }
}

$rows = q_all(
    "SELECT found_reports.id, found_reports.ref_number, found_reports.category,
            found_reports.color, found_reports.description, found_reports.date_found,
            storage_locations.code AS storage_code
       FROM found_reports
       JOIN storage_locations ON storage_locations.id = found_reports.storage_location_id
      WHERE found_reports.status = 'expired'
      ORDER BY found_reports.date_found ASC, found_reports.id ASC"
);

layout_open('Donate Expired Items');

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
    ['Dashboard', url('/index.php?p=admin.dashboard')],
    ['Donate Expired Items'],
]);

page_header('Donate Expired Items');
?>

<div class="card">
  <p class="card-subtitle">
    Items past the holding period are marked <strong>Expired</strong> by the daily job.
    Select the ones being handed to a partner or beneficiary and record where they went —
    this writes a permanent audit entry. Donation cannot be undone.
  </p>

  <?php if (!$rows): ?>
    <div class="empty-state">
      <p class="empty-state-title">No expired items awaiting donation</p>
      <p class="empty-state-body">When items pass the holding period, they will appear here for donation.</p>
    </div>
  <?php else: ?>
    <form method="POST" id="donate-form">
      <?= csrf_field() ?>

      <div class="table-wrap table-wrap-cards">
        <table class="data-table data-table-static">
          <thead>
            <tr>
              <th class="col-narrow">
                <input type="checkbox" id="donate-select-all" aria-label="Select all expired items">
              </th>
              <th>Reference</th>
              <th>Item</th>
              <th class="col-narrow">Location</th>
              <th class="col-narrow">Date found</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td data-label="" class="col-narrow">
                  <input type="checkbox" name="ids[]" value="<?= (int) $r['id'] ?>"
                         aria-label="Select <?= e((string) $r['ref_number']) ?>">
                </td>
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
                <td data-label="Date found" class="col-narrow text-sm text-muted"><?= e(date('M j, Y', strtotime((string) $r['date_found']))) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div style="margin-top: var(--space-4);">
        <button type="button" class="btn btn-primary" data-modal-open="donate-modal" data-donate-open>
          Donate selected
        </button>
      </div>

      <?php
        modal_open('donate-modal', 'Donate selected items', [
            'role'        => 'alertdialog',
            'describedby' => 'donate-modal-desc',
        ]);
      ?>
        <p id="donate-modal-desc">
          The selected items will be marked <strong>Donated</strong> and recorded in
          the audit log. This cannot be undone.
        </p>
        <div class="form-group">
          <label for="beneficiary" class="form-label form-label-required">Partner / beneficiary</label>
          <textarea id="beneficiary" name="beneficiary" rows="3" required
                    class="form-control"
                    placeholder="e.g. Donated to the FEU Community Outreach drive; received by J. Santos."></textarea>
        </div>
      <?php
        modal_footer_open();
      ?>
        <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-danger">Donate items</button>
      <?php
        modal_footer_close();
        modal_close();
      ?>
    </form>

    <script>
    (function () {
      var form = document.getElementById('donate-form');
      if (!form) return;
      var boxes = Array.prototype.slice.call(form.querySelectorAll('input[name="ids[]"]'));
      var all   = document.getElementById('donate-select-all');
      var openBtn = form.querySelector('[data-donate-open]');
      function selectedCount() {
        return boxes.filter(function (b) { return b.checked; }).length;
      }
      function sync() {
        if (openBtn) openBtn.disabled = selectedCount() === 0;
        if (all) all.checked = boxes.length > 0 && selectedCount() === boxes.length;
      }
      if (all) {
        all.addEventListener('change', function () {
          boxes.forEach(function (b) { b.checked = all.checked; });
          sync();
        });
      }
      boxes.forEach(function (b) { b.addEventListener('change', sync); });
      sync();
    }());
    </script>
  <?php endif; ?>
</div>

<?php
layout_close();
