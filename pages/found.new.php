<?php
declare(strict_types=1);

/**
 * Log Found Item — create + edit (staff/admin only; routes.php enforces role).
 *
 *   GET  /index.php?p=found.new            New entry form (date defaults to today).
 *   GET  /index.php?p=found.new&id=N       Edit form (status=open only).
 *   POST /index.php?p=found.new[&id=N]     Insert or update.
 *
 * On insert success → redirect to found.created?id=N with the reference number.
 * On update success → redirect to found.show?id=N with a flash.
 *
 * No matching service is called here. Task 11 wires it into the insert path.
 */

$user        = current_user();
$user_id     = (int) $user['id'];
$categories  = item_categories();

$locations    = q_all(
    'SELECT id, code, description FROM storage_locations WHERE is_active = 1 ORDER BY code'
);
$location_ids = array_map('strval', array_column($locations, 'id'));

$edit_id  = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$is_edit  = $edit_id > 0;
$existing = null;

if ($is_edit) {
    $existing = q_one('SELECT * FROM found_reports WHERE id = ? LIMIT 1', [$edit_id]);
    if ($existing === null) {
        http_response_code(404);
        require __DIR__ . '/404.php';
        exit;
    }
    if ($existing['status'] !== 'open') {
        flash_set('info', 'This entry can no longer be edited because the item has progressed past OPEN.');
        go(url('/index.php?p=found.show&id=' . $edit_id));
    }
}

// -----------------------------------------------------------------------------
// POST handler
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $category            = trim((string) ($_POST['category']            ?? ''));
    $color               = trim((string) ($_POST['color']               ?? ''));
    $brand               = clean((string) ($_POST['brand']               ?? ''));
    $description         = clean((string) ($_POST['description']         ?? ''));
    $storage_location_id = trim((string) ($_POST['storage_location_id'] ?? ''));
    $date_found          = trim((string) ($_POST['date_found']           ?? ''));

    $errors = validate(
        compact('category', 'color', 'brand', 'description', 'storage_location_id', 'date_found'),
        [
            'category'            => 'required|enum:' . implode(',', array_keys($categories)),
            'color'               => 'required|max:50',
            'brand'               => 'max:100',
            'description'         => 'required|min:10|max:1000',
            'storage_location_id' => 'required|enum:' . (implode(',', $location_ids) ?: '0'),
            'date_found'          => 'required|date',
        ]
    );

    if (!isset($errors['date_found']) && strtotime($date_found) > time()) {
        $errors['date_found'][] = 'Date found cannot be in the future.';
    }

    if (!$errors) {
        try {
            $new_id = db_transaction(function () use (
                $is_edit, $edit_id, $user_id, $category, $color, $brand,
                $description, $storage_location_id, $date_found
            ) {
                if ($is_edit) {
                    q(
                        'UPDATE found_reports
                            SET category = ?, color = ?, brand = ?, description = ?,
                                storage_location_id = ?, date_found = ?
                          WHERE id = ? AND status = ?',
                        [$category, $color, $brand, $description,
                         (int) $storage_location_id, $date_found, $edit_id, 'open']
                    );
                    audit_log('found_report.update', 'found_report', $edit_id, [
                        'category'    => $category,
                        'description' => $description,
                    ]);
                    $id = $edit_id;
                } else {
                    $placeholder = '_pending_' . bin2hex(random_bytes(8));
                    q(
                        'INSERT INTO found_reports
                           (ref_number, finder_account_id, category, color, brand,
                            description, storage_location_id, date_found, status)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [$placeholder, $user_id, $category, $color, $brand,
                         $description, (int) $storage_location_id, $date_found, 'open']
                    );
                    $id  = db_last_id();
                    $ref = make_ref_number('found', $id);
                    q('UPDATE found_reports SET ref_number = ? WHERE id = ?', [$ref, $id]);
                    audit_log('found_report.create', 'found_report', $id, [
                        'status' => [null, 'open'],
                        'ref'    => $ref,
                    ]);
                }

                if (!empty($_FILES['photo']['name'])
                    && (int) ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    upload_store($_FILES['photo'], 'found_report', $id, 'photo');
                }

                if (!$is_edit) {
                    generate_candidates_for_found($id);
                }

                return $id;
            });

            if ($is_edit) {
                flash_set('success', 'Found item updated.');
                go(url('/index.php?p=found.show&id=' . $new_id));
            }
            go(url('/index.php?p=found.created&id=' . $new_id));
        } catch (\Throwable $e) {
            $errors['_form'] = ['Could not save entry: ' . $e->getMessage()];
        }
    }

    back([
        'errors' => $errors,
        'old'    => compact('category', 'color', 'brand', 'description', 'storage_location_id', 'date_found'),
    ]);
}

// -----------------------------------------------------------------------------
// GET render
// -----------------------------------------------------------------------------

$errors = flash_get('errors') ?: [];
$old    = flash_get('old')    ?: [];

if ($is_edit && !$old) {
    $old = [
        'category'            => $existing['category'],
        'color'               => $existing['color'],
        'brand'               => $existing['brand'] ?? '',
        'description'         => $existing['description'],
        'storage_location_id' => (string) $existing['storage_location_id'],
        'date_found'          => $existing['date_found'],
    ];
}

$page_title   = $is_edit ? 'Edit found item' : 'Log a found item';
$submit_label = $is_edit ? 'Save changes'    : 'Submit found item';

layout_open($page_title);

breadcrumb([
    ['Dashboard',    url('/index.php?p=staff.dashboard')],
    ['Found Items',  url('/index.php?p=found')],
    [$is_edit ? 'Edit ' . $existing['ref_number'] : 'Log found item'],
]);

page_header($page_title);
?>

<?php if (!empty($errors['_form'])): ?>
  <div class="alert alert-danger" role="alert"><?= e($errors['_form'][0]) ?></div>
<?php endif; ?>

<?php if (!$locations): ?>
  <div class="alert alert-warning" role="alert">
    No storage locations have been configured. Ask an administrator to add at least one location under
    Admin &rarr; Settings &rarr; Storage Locations before logging a found item.
  </div>
<?php endif; ?>

<div class="card" style="max-width: var(--max-width-form);">
  <p class="card-subtitle">
    Be specific about distinctive details (brand, stickers, contents, scratches).
    The matching service uses your description against open lost reports.
  </p>

  <form method="POST"
        action="<?= e(url('/index.php?p=found.new' . ($is_edit ? '&id=' . $edit_id : ''))) ?>"
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
              required<?= field_aria('category', $errors) ?>>
        <option value="">Choose one&hellip;</option>
        <?php foreach ($categories as $value => $label): ?>
          <option value="<?= e($value) ?>"<?= ($old['category'] ?? '') === $value ? ' selected' : '' ?>>
            <?= e($label) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <?= field_error_html('category', $errors, 'field-error-text') ?>
    </div>

    <div class="field<?= isset($errors['color']) ? ' field-error' : '' ?>">
      <label class="field-label field-label-required" for="color">Primary color</label>
      <input type="text" id="color" name="color" class="field-input"
             value="<?= e($old['color'] ?? '') ?>"
             data-rule="required|max:50" required maxlength="50"<?= field_aria('color', $errors) ?>>
      <?= field_error_html('color', $errors, 'field-error-text') ?>
    </div>

    <div class="field<?= isset($errors['brand']) ? ' field-error' : '' ?>">
      <label class="field-label" for="brand">Brand or identifying marks <span class="text-muted text-sm">(optional)</span></label>
      <input type="text" id="brand" name="brand" class="field-input"
             value="<?= e($old['brand'] ?? '') ?>"
             data-rule="max:100" maxlength="100"<?= field_aria('brand', $errors) ?>>
      <p class="field-helper">Stickers, logos, scratches, engravings &mdash; anything distinctive.</p>
      <?= field_error_html('brand', $errors, 'field-error-text') ?>
    </div>

    <div class="field<?= isset($errors['description']) ? ' field-error' : '' ?>">
      <label class="field-label field-label-required" for="description">Description</label>
      <textarea id="description" name="description" class="field-textarea"
                data-rule="required|min:10|max:1000" required maxlength="1000"
                rows="4"
                placeholder="What's inside? What does it look like? What condition is it in?"<?= field_aria('description', $errors) ?>><?= e($old['description'] ?? '') ?></textarea>
      <p class="field-helper">For verification at release time, note contents and any wear. Do NOT inspect wallets or sealed containers &mdash; describe them externally only.</p>
      <?= field_error_html('description', $errors, 'field-error-text') ?>
    </div>

    <div class="field<?= isset($errors['storage_location_id']) ? ' field-error' : '' ?>">
      <label class="field-label field-label-required" for="storage_location_id">Storage location</label>
      <select id="storage_location_id" name="storage_location_id" class="field-select"
              data-rule="required|enum:<?= e(implode(',', $location_ids) ?: '0') ?>"
              required <?= !$locations ? 'disabled' : '' ?><?= field_aria('storage_location_id', $errors) ?>>
        <option value="">Choose one&hellip;</option>
        <?php foreach ($locations as $loc): ?>
          <option value="<?= e((string) $loc['id']) ?>"
                  <?= ($old['storage_location_id'] ?? '') === (string) $loc['id'] ? 'selected' : '' ?>>
            <?= e((string) $loc['code']) ?> &mdash; <?= e((string) $loc['description']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <p class="field-helper">Where will you physically put this item? Manage locations under Admin &rarr; Storage Locations.</p>
      <?= field_error_html('storage_location_id', $errors, 'field-error-text') ?>
    </div>

    <div class="field<?= isset($errors['date_found']) ? ' field-error' : '' ?>">
      <label class="field-label field-label-required" for="date_found">Date found</label>
      <input type="date" id="date_found" name="date_found" class="field-input"
             value="<?= e($old['date_found'] ?? date('Y-m-d')) ?>"
             max="<?= e(date('Y-m-d')) ?>"
             min="<?= e(date('Y-m-d', strtotime('-1 year'))) ?>"
             data-rule="required|date" required<?= field_aria('date_found', $errors) ?>>
      <?= field_error_html('date_found', $errors, 'field-error-text') ?>
    </div>

    <div class="photo-upload" data-max-bytes="<?= e((string) upload_max_bytes()) ?>">
      <label class="field-label" for="photo">Photo <span class="text-muted text-sm">(strongly encouraged)</span></label>
      <div class="photo-upload-zone" tabindex="0" role="button" aria-label="Browse or drop a photo">
        <input type="file" id="photo" name="photo" accept="image/jpeg,image/png,image/webp">
        <p class="photo-upload-prompt">
          <strong>Click to browse</strong> or drag a photo here.<br>
          <span class="text-xs">A clear photo improves matching accuracy. JPEG, PNG, or WebP up to <?= e(number_format(upload_max_bytes() / 1024 / 1024, 0)) ?> MB.</span>
        </p>
      </div>
      <div class="photo-upload-preview" hidden>
        <img alt="Selected photo preview">
        <a href="#" class="photo-upload-remove btn-link">Remove photo</a>
      </div>
      <p class="field-error-text" hidden></p>
    </div>

    <div class="mt-4" style="display: flex; gap: var(--space-3);">
      <button type="submit" class="btn btn-primary btn-lg" <?= !$locations ? 'disabled' : '' ?>>
        <?= e($submit_label) ?>
      </button>
      <a href="<?= e($is_edit ? url('/index.php?p=found.show&id=' . $edit_id) : url('/index.php?p=staff.dashboard')) ?>"
         class="btn btn-ghost btn-lg">Cancel</a>
    </div>
  </form>
</div>

<?php
layout_close();
