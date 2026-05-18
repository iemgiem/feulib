<?php
declare(strict_types=1);

/**
 * Report Lost Item — create + edit.
 *
 *   GET  /index.php?p=lost.new         New report form.
 *   GET  /index.php?p=lost.new&id=N    Edit form (only if status=open AND user owns it).
 *   POST /index.php?p=lost.new[&id=N]  Insert or update accordingly.
 *
 * On insert success → redirect to lost.created?id=N with the reference number.
 * On update success → redirect to lost.show?id=N with a success flash.
 * On any failure   → back() with errors + old, never the photo (HTML can't restore it).
 */

$user        = current_user();
$user_id     = (int) $user['id'];
$categories  = item_categories();

$edit_id     = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$is_edit     = $edit_id > 0;
$existing    = null;

if ($is_edit) {
    $existing = q_one(
        'SELECT * FROM lost_reports WHERE id = ? AND reporter_account_id = ? LIMIT 1',
        [$edit_id, $user_id]
    );
    if ($existing === null) {
        // Not theirs (or doesn't exist) — front-controller will not have caught this
        // because the page token itself is open to all users.
        http_response_code(404);
        require __DIR__ . '/404.php';
        exit;
    }
    if ($existing['status'] !== 'open') {
        flash_set('info', 'This report can no longer be edited because its status has changed.');
        go(url('/index.php?p=lost.show&id=' . $edit_id));
    }
}

// -----------------------------------------------------------------------------
// POST handler
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $category   = trim((string) ($_POST['category']  ?? ''));
    $color      = trim((string) ($_POST['color']     ?? ''));
    $brand      = clean((string) ($_POST['brand']    ?? ''));
    $description= clean((string) ($_POST['description'] ?? ''));
    $last_seen  = clean((string) ($_POST['last_seen']?? ''));
    $date_lost  = trim((string) ($_POST['date_lost'] ?? ''));

    $errors = validate(
        compact('category', 'color', 'brand', 'description', 'last_seen', 'date_lost'),
        [
            'category'    => 'required|enum:' . implode(',', array_keys($categories)),
            'color'       => 'required|max:50',
            'brand'       => 'max:100',
            'description' => 'required|min:10|max:1000',
            'last_seen'   => 'required|max:255',
            'date_lost'   => 'required|date',
        ]
    );

    // Date sanity — cannot be in the future
    if (!isset($errors['date_lost']) && strtotime($date_lost) > time()) {
        $errors['date_lost'][] = 'Date lost cannot be in the future.';
    }

    if (!$errors) {
        try {
            $new_id = db_transaction(function () use (
                $is_edit, $edit_id, $user_id, $category, $color, $brand,
                $description, $last_seen, $date_lost
            ) {
                if ($is_edit) {
                    q(
                        'UPDATE lost_reports
                            SET category = ?, color = ?, brand = ?, description = ?,
                                last_seen_location = ?, date_lost = ?
                          WHERE id = ? AND reporter_account_id = ? AND status = ?',
                        [$category, $color, $brand, $description, $last_seen, $date_lost,
                         $edit_id, $user_id, 'open']
                    );
                    audit_log('lost_report.update', 'lost_report', $edit_id, [
                        'category'    => $category,
                        'description' => $description,
                    ]);
                    $id = $edit_id;
                } else {
                    $placeholder = '_pending_' . bin2hex(random_bytes(8));
                    q(
                        'INSERT INTO lost_reports
                           (ref_number, reporter_account_id, category, color, brand,
                            description, last_seen_location, date_lost, status)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [$placeholder, $user_id, $category, $color, $brand,
                         $description, $last_seen, $date_lost, 'open']
                    );
                    $id  = db_last_id();
                    $ref = make_ref_number('lost', $id);
                    q('UPDATE lost_reports SET ref_number = ? WHERE id = ?', [$ref, $id]);
                    audit_log('lost_report.create', 'lost_report', $id, [
                        'status' => [null, 'open'],
                        'ref'    => $ref,
                    ]);
                }

                // Optional photo — inside the same transaction so rollback wipes it.
                if (!empty($_FILES['photo']['name'])
                    && (int) ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    upload_store($_FILES['photo'], 'lost_report', $id, 'photo');
                }

                if (!$is_edit) {
                    generate_candidates_for_lost($id);
                }

                return $id;
            });

            if ($is_edit) {
                flash_set('success', 'Report updated.');
                go(url('/index.php?p=lost.show&id=' . $new_id));
            }
            go(url('/index.php?p=lost.created&id=' . $new_id));
        } catch (\Throwable $e) {
            $errors['_form'] = ['Could not save report: ' . $e->getMessage()];
        }
    }

    back([
        'errors' => $errors,
        'old'    => compact('category', 'color', 'brand', 'description', 'last_seen', 'date_lost'),
    ]);
}

// -----------------------------------------------------------------------------
// GET render
// -----------------------------------------------------------------------------

$errors = flash_get('errors') ?: [];
$old    = flash_get('old')    ?: [];

// On edit, prefill from DB if we don't already have flashed-back values
if ($is_edit && !$old) {
    $old = [
        'category'    => $existing['category'],
        'color'       => $existing['color'],
        'brand'       => $existing['brand'] ?? '',
        'description' => $existing['description'],
        'last_seen'   => $existing['last_seen_location'],
        'date_lost'   => $existing['date_lost'],
    ];
}

$page_title  = $is_edit ? 'Edit lost report' : 'Report a lost item';
$submit_label= $is_edit ? 'Save changes'      : 'Submit report';

layout_open($page_title);

breadcrumb([
    ['Dashboard',        url('/index.php?p=dashboard')],
    ['My Lost Reports',  url('/index.php?p=lost')],
    [$is_edit ? 'Edit ' . $existing['ref_number'] : 'Report lost item'],
]);

page_header($page_title);
?>

<?php if (!empty($errors['_form'])): ?>
  <div class="alert alert-danger" role="alert"><?= e($errors['_form'][0]) ?></div>
<?php endif; ?>

<div class="card" style="max-width: var(--max-width-form);">
  <p class="card-subtitle">
    Describe the item as specifically as you can. Distinctive details
    (brand, stickers, scratches, contents) make a match far more likely.
  </p>

  <form method="POST"
        action="<?= e(url('/index.php?p=lost.new' . ($is_edit ? '&id=' . $edit_id : ''))) ?>"
        enctype="multipart/form-data"
        data-validate
        novalidate>
    <?= csrf_field() ?>
    <?php if ($is_edit): ?>
      <input type="hidden" name="id" value="<?= e((string) $edit_id) ?>">
    <?php endif; ?>

    <div class="field<?= isset($errors['category']) ? ' field-error' : '' ?>">
      <label class="field-label field-label-required" for="category">Category</label>
      <select id="category" name="category" class="field-select"
              data-rule="required|enum:<?= e(implode(',', array_keys($categories))) ?>"
              required>
        <option value="">Choose one&hellip;</option>
        <?php foreach ($categories as $value => $label): ?>
          <option value="<?= e($value) ?>"<?= ($old['category'] ?? '') === $value ? ' selected' : '' ?>>
            <?= e($label) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <?php if (isset($errors['category'])): ?>
        <p class="field-error-text"><?= e($errors['category'][0]) ?></p>
      <?php endif; ?>
    </div>

    <div class="field<?= isset($errors['color']) ? ' field-error' : '' ?>">
      <label class="field-label field-label-required" for="color">Primary color</label>
      <input type="text" id="color" name="color" class="field-input"
             value="<?= e($old['color'] ?? '') ?>"
             data-rule="required|max:50" required maxlength="50">
      <p class="field-helper">Pick the most dominant color (e.g., navy, black, pink).</p>
      <?php if (isset($errors['color'])): ?>
        <p class="field-error-text"><?= e($errors['color'][0]) ?></p>
      <?php endif; ?>
    </div>

    <div class="field<?= isset($errors['brand']) ? ' field-error' : '' ?>">
      <label class="field-label" for="brand">Brand or identifying marks <span class="text-muted text-sm">(optional)</span></label>
      <input type="text" id="brand" name="brand" class="field-input"
             value="<?= e($old['brand'] ?? '') ?>"
             data-rule="max:100" maxlength="100">
      <p class="field-helper">E.g., "Jansport", "Apple", or any logo / scratch / sticker.</p>
      <?php if (isset($errors['brand'])): ?>
        <p class="field-error-text"><?= e($errors['brand'][0]) ?></p>
      <?php endif; ?>
    </div>

    <div class="field<?= isset($errors['description']) ? ' field-error' : '' ?>">
      <label class="field-label field-label-required" for="description">Description</label>
      <textarea id="description" name="description" class="field-textarea"
                data-rule="required|min:10|max:1000" required maxlength="1000"
                rows="4"><?= e($old['description'] ?? '') ?></textarea>
      <p class="field-helper">Describe the item in detail. Mention any contents, distinctive marks, or wear &mdash; staff will use this to verify your ownership.</p>
      <?php if (isset($errors['description'])): ?>
        <p class="field-error-text"><?= e($errors['description'][0]) ?></p>
      <?php endif; ?>
    </div>

    <div class="field<?= isset($errors['last_seen']) ? ' field-error' : '' ?>">
      <label class="field-label field-label-required" for="last_seen">Last seen location</label>
      <input type="text" id="last_seen" name="last_seen" class="field-input"
             value="<?= e($old['last_seen'] ?? '') ?>"
             data-rule="required|max:255" required maxlength="255"
             placeholder="e.g., 3rd floor study area">
      <?php if (isset($errors['last_seen'])): ?>
        <p class="field-error-text"><?= e($errors['last_seen'][0]) ?></p>
      <?php endif; ?>
    </div>

    <div class="field<?= isset($errors['date_lost']) ? ' field-error' : '' ?>">
      <label class="field-label field-label-required" for="date_lost">Date lost</label>
      <input type="date" id="date_lost" name="date_lost" class="field-input"
             value="<?= e($old['date_lost'] ?? '') ?>"
             max="<?= e(date('Y-m-d')) ?>"
             min="<?= e(date('Y-m-d', strtotime('-1 year'))) ?>"
             data-rule="required|date" required>
      <?php if (isset($errors['date_lost'])): ?>
        <p class="field-error-text"><?= e($errors['date_lost'][0]) ?></p>
      <?php endif; ?>
    </div>

    <div class="photo-upload" data-max-bytes="<?= e((string) upload_max_bytes()) ?>">
      <label class="field-label" for="photo">Photo <span class="text-muted text-sm">(optional, helps staff identify it)</span></label>
      <div class="photo-upload-zone" tabindex="0" role="button" aria-label="Browse or drop a photo">
        <input type="file" id="photo" name="photo" accept="image/jpeg,image/png,image/webp">
        <p class="photo-upload-prompt">
          <strong>Click to browse</strong> or drag a photo here.<br>
          <span class="text-xs">JPEG, PNG, or WebP. Up to <?= e(number_format(upload_max_bytes() / 1024 / 1024, 0)) ?> MB.</span>
        </p>
      </div>
      <div class="photo-upload-preview" hidden>
        <img alt="Selected photo preview">
        <a href="#" class="photo-upload-remove btn-link">Remove photo</a>
      </div>
      <p class="field-error-text" hidden></p>
    </div>

    <div class="stack-3 mt-4" style="display: flex; gap: var(--space-3);">
      <button type="submit" class="btn btn-primary btn-lg"><?= e($submit_label) ?></button>
      <a href="<?= e($is_edit ? url('/index.php?p=lost.show&id=' . $edit_id) : url('/index.php?p=dashboard')) ?>"
         class="btn btn-ghost btn-lg">Cancel</a>
    </div>
  </form>
</div>

<?php
layout_close();
