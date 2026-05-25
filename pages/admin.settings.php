<?php
declare(strict_types=1);

/**
 * Admin settings — tabbed via ?tab=.
 *
 * Each tab is a plain server route (no JS): the tab strip is a <nav> of links,
 * and POST handlers dispatch on the hidden `section` field then redirect back
 * to the originating tab. On validation failure the page re-renders the posted
 * tab with errors.
 *
 * Built: Storage Locations (CRUD), Holding Period, Match Scoring Weights,
 * Backup Status (read-only). Planned (Task 19): Users & Roles, Notification
 * Rules — rendered as advisory panels until built.
 */

$user    = current_user();
$user_id = (int) $user['id'];

$TABS = [
    'users'         => 'Users & Roles',
    'storage'       => 'Storage Locations',
    'holding'       => 'Holding Period',
    'notifications' => 'Notification Rules',
    'scoring'       => 'Match Scoring',
    'backup'        => 'Backup Status',
];

$tab = (string) ($_GET['tab'] ?? 'storage');
if (!isset($TABS[$tab])) {
    $tab = 'storage';
}

/** Load all settings into a flat key => value array. */
function _settings_all(): array
{
    $out = [];
    foreach (q_all('SELECT key_name, value FROM settings') as $row) {
        $out[(string) $row['key_name']] = $row['value'];
    }
    return $out;
}

/** Validate a storage-location form payload → [code, description, errors]. */
function _storage_validate(array $post): array
{
    $code = trim((string) ($post['code'] ?? ''));
    $desc = trim((string) ($post['description'] ?? ''));
    $errors = [];

    if ($code === '') {
        $errors['code'][] = 'Code is required.';
    } elseif (mb_strlen($code) > 20) {
        $errors['code'][] = 'Code must be 20 characters or fewer.';
    }
    if ($desc === '') {
        $errors['description'][] = 'Description is required.';
    } elseif (mb_strlen($desc) > 255) {
        $errors['description'][] = 'Description must be 255 characters or fewer.';
    }
    return [$code, $desc, $errors];
}

$settings = _settings_all();
$setting  = static fn(string $key, string $default = '') => $settings[$key] ?? $default;

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $section = $_POST['section'] ?? '';

    if ($section === 'match_weights') {
        $keys = [
            'match_weight_category',
            'match_weight_color',
            'match_weight_location',
            'match_weight_date',
            'match_weight_description',
            'match_threshold',
        ];
        $values = [];
        $total_weights = 0;
        foreach ($keys as $key) {
            $val = (int) ($_POST[$key] ?? 0);
            if ($key !== 'match_threshold') {
                if ($val < 0 || $val > 100) {
                    $errors[$key] = ['Must be between 0 and 100.'];
                }
                $total_weights += $val;
            } else {
                if ($val < 0 || $val > 100) {
                    $errors[$key] = ['Must be between 0 and 100.'];
                }
            }
            $values[$key] = (string) $val;
        }
        if (!$errors && $total_weights !== 100) {
            $errors['match_weight_category'] = ['The five weights must add up to exactly 100. Currently: ' . $total_weights . '.'];
        }

        if (!$errors) {
            db_transaction(function () use ($values, $user_id) {
                foreach ($values as $key => $val) {
                    q(
                        'INSERT INTO settings (key_name, value, updated_by_account_id)
                         VALUES (?, ?, ?)
                         ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW(),
                             updated_by_account_id = VALUES(updated_by_account_id)',
                        [$key, $val, $user_id]
                    );
                }
                audit_log('settings.update_match_weights', 'settings', 0);
            });
            flash_set('success', 'Match scoring weights saved.');
            go(url('/index.php?p=admin.settings&tab=scoring'));
        }
    } elseif ($section === 'holding_period') {
        $days = (int) ($_POST['holding_period_days'] ?? 0);
        if ($days < 1 || $days > 365) {
            $errors['holding_period_days'] = ['Must be between 1 and 365 days.'];
        }

        if (!$errors) {
            q(
                'INSERT INTO settings (key_name, value, updated_by_account_id)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW(),
                     updated_by_account_id = VALUES(updated_by_account_id)',
                ['holding_period_days', (string) $days, $user_id]
            );
            audit_log('settings.update_holding_period', 'settings', 0);
            flash_set('success', 'Holding period saved.');
            go(url('/index.php?p=admin.settings&tab=holding'));
        }
    } elseif ($section === 'storage_create') {
        [$code, $desc, $errors] = _storage_validate($_POST);
        if (!$errors && (int) q_value('SELECT COUNT(*) FROM storage_locations WHERE code = ?', [$code]) > 0) {
            $errors['code'][] = 'That code is already in use.';
        }
        if (!$errors) {
            try {
                q('INSERT INTO storage_locations (code, description) VALUES (?, ?)', [$code, $desc]);
                audit_log('storage.create', 'storage_location', (int) db_last_id(), [
                    'code' => $code, 'description' => $desc,
                ]);
                flash_set('success', 'Storage location “' . $code . '” added.');
                go(url('/index.php?p=admin.settings&tab=storage'));
            } catch (\Throwable $e) {
                $errors['code'][] = 'Could not save the location. Please try again.';
            }
        }
    } elseif ($section === 'storage_update') {
        $id = (int) ($_POST['id'] ?? 0);
        [$code, $desc, $errors] = _storage_validate($_POST);
        if (!$errors && (int) q_value('SELECT COUNT(*) FROM storage_locations WHERE code = ? AND id <> ?', [$code, $id]) > 0) {
            $errors['code'][] = 'That code is already in use.';
        }
        if (!$errors && $id > 0) {
            try {
                q('UPDATE storage_locations SET code = ?, description = ? WHERE id = ?', [$code, $desc, $id]);
                audit_log('storage.update', 'storage_location', $id, [
                    'code' => $code, 'description' => $desc,
                ]);
                flash_set('success', 'Storage location updated.');
                go(url('/index.php?p=admin.settings&tab=storage'));
            } catch (\Throwable $e) {
                $errors['code'][] = 'Could not save the location. Please try again.';
            }
        }
    } elseif ($section === 'storage_set_active') {
        $id     = (int) ($_POST['id'] ?? 0);
        $active = ((int) ($_POST['active'] ?? 0)) === 1 ? 1 : 0;
        if ($id > 0) {
            q('UPDATE storage_locations SET is_active = ? WHERE id = ?', [$active, $id]);
            audit_log($active ? 'storage.activate' : 'storage.deactivate', 'storage_location', $id, [
                'is_active' => $active,
            ]);
            flash_set('success', $active ? 'Storage location activated.' : 'Storage location deactivated.');
            go(url('/index.php?p=admin.settings&tab=storage'));
        }
    } elseif ($section === 'user_set_role') {
        $id       = (int) ($_POST['id'] ?? 0);
        $new_role = (string) ($_POST['new_role'] ?? '');
        $target   = $id > 0 ? q_one('SELECT id, role, is_active FROM accounts WHERE id = ?', [$id]) : null;

        if (!in_array($new_role, ['user', 'staff', 'admin'], true) || $target === null) {
            $errors['users'][] = 'Could not change the role for that account.';
        } elseif ($id === $user_id) {
            $errors['users'][] = 'You cannot change your own role.';
        } elseif (
            $target['role'] === 'admin' && $new_role !== 'admin'
            && (int) $target['is_active'] === 1
            && (int) q_value("SELECT COUNT(*) FROM accounts WHERE role = 'admin' AND is_active = 1") <= 1
        ) {
            $errors['users'][] = 'Cannot demote the last active administrator.';
        } elseif ($new_role === $target['role']) {
            go(url('/index.php?p=admin.settings&tab=users')); // no change
        } else {
            if ($new_role === 'user') {
                q('UPDATE accounts SET role = ? WHERE id = ?', ['user', $id]);
            } else {
                // user_type only applies to the 'user' role; clear it on promotion.
                q('UPDATE accounts SET role = ?, user_type = NULL WHERE id = ?', [$new_role, $id]);
            }
            audit_log('account.role_change', 'account', $id, [
                'role' => [(string) $target['role'], $new_role],
            ]);
            flash_set('success', 'Role updated.');
            go(url('/index.php?p=admin.settings&tab=users'));
        }
    } elseif ($section === 'user_set_active') {
        $id     = (int) ($_POST['id'] ?? 0);
        $active = ((int) ($_POST['active'] ?? 0)) === 1 ? 1 : 0;
        $target = $id > 0 ? q_one('SELECT id, role, is_active FROM accounts WHERE id = ?', [$id]) : null;

        if ($target === null) {
            $errors['users'][] = 'Could not update that account.';
        } elseif ($id === $user_id && $active === 0) {
            $errors['users'][] = 'You cannot deactivate your own account.';
        } elseif (
            $active === 0 && $target['role'] === 'admin' && (int) $target['is_active'] === 1
            && (int) q_value("SELECT COUNT(*) FROM accounts WHERE role = 'admin' AND is_active = 1") <= 1
        ) {
            $errors['users'][] = 'Cannot deactivate the last active administrator.';
        } else {
            q('UPDATE accounts SET is_active = ? WHERE id = ?', [$active, $id]);
            audit_log($active ? 'account.activate' : 'account.deactivate', 'account', $id, [
                'is_active' => $active,
            ]);
            flash_set('success', $active ? 'Account activated.' : 'Account deactivated.');
            go(url('/index.php?p=admin.settings&tab=users'));
        }
    } elseif ($section === 'user_create') {
        $c_full_name  = clean((string) ($_POST['full_name']        ?? ''));
        $c_user_type  = (string) ($_POST['user_type']              ?? '');
        $c_id_number  = clean_id((string) ($_POST['id_number']     ?? ''));
        $c_email      = trim((string) ($_POST['email']             ?? ''));
        $c_role       = (string) ($_POST['role']                   ?? '');
        $c_password   = (string) ($_POST['password']               ?? '');
        $c_password_c = (string) ($_POST['password_confirm']       ?? '');

        $errors = validate(
            [
                'full_name'        => $c_full_name,
                'id_number'        => $c_id_number,
                'email'            => $c_email,
                'password'         => $c_password,
                'password_confirm' => $c_password_c,
            ],
            [
                'full_name' => 'required|min:2|max:150',
                'id_number' => 'required|max:50',
                'email'     => 'required|email|max:255',
                'password'  => 'required|min:8|max:255|confirmed',
            ]
        );

        if (!in_array($c_role, ['user', 'staff', 'admin'], true)) {
            $errors['role'][] = 'Please select a valid role.';
        }

        // user_type + ID format check only apply to the 'user' role.
        // Staff and Admin may have any ID format (or a simple employee number).
        if (!isset($errors['role']) && $c_role === 'user') {
            if (!in_array($c_user_type, ['student', 'faculty'], true)) {
                $errors['user_type'][] = 'Please select Student or Faculty.';
            } elseif (!isset($errors['id_number'])) {
                $pattern = $c_user_type === 'faculty'
                    ? '/^\d{4}-EMP-\d{1,6}$/'
                    : '/^\d{4}-\d{4,8}$/';
                if (!preg_match($pattern, $c_id_number)) {
                    $errors['id_number'][] = $c_user_type === 'faculty'
                        ? 'Faculty IDs look like 2020-EMP-001.'
                        : 'Student numbers look like 2024-00001.';
                }
            }
        }

        if (!isset($errors['email'])) {
            if (q_value('SELECT 1 FROM accounts WHERE email = ? LIMIT 1', [$c_email])) {
                $errors['email'][] = 'This email is already registered.';
            }
        }
        if (!isset($errors['id_number'])) {
            if (q_value('SELECT 1 FROM accounts WHERE id_number = ? LIMIT 1', [$c_id_number])) {
                $errors['id_number'][] = 'This ID number is already registered.';
            }
        }

        if (!$errors) {
            $hash            = password_hash($c_password, PASSWORD_DEFAULT);
            $final_user_type = ($c_role === 'user') ? $c_user_type : null;
            db_transaction(function () use ($c_full_name, $final_user_type, $c_id_number, $c_email, $c_role, $hash) {
                q(
                    'INSERT INTO accounts (role, user_type, full_name, id_number, email, password_hash, is_active)
                     VALUES (?, ?, ?, ?, ?, ?, 1)',
                    [$c_role, $final_user_type, $c_full_name, $c_id_number, $c_email, $hash]
                );
                audit_log('account.admin_create', 'account', (int) db_last_id(), [
                    'role'      => $c_role,
                    'user_type' => $final_user_type,
                ]);
            });
            flash_set('success', 'Account created for ' . $c_full_name . '.');
            go(url('/index.php?p=admin.settings&tab=users'));
        }
    } elseif ($section === 'notification_rules') {
        foreach (array_keys(notify_event_types()) as $type) {
            q(
                'INSERT INTO settings (key_name, value, updated_by_account_id)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW(),
                     updated_by_account_id = VALUES(updated_by_account_id)',
                ['notify_' . $type, isset($_POST['notify'][$type]) ? '1' : '0', $user_id]
            );
        }
        audit_log('settings.update_notifications', 'settings', 0);
        flash_set('success', 'Notification rules saved.');
        go(url('/index.php?p=admin.settings&tab=notifications'));
    }

    // Validation failed (success paths redirect above): keep the user on the
    // tab they posted from, and refresh the settings cache.
    $settings = _settings_all();
    $setting  = static fn(string $key, string $default = '') => $settings[$key] ?? $default;
    $posted_tab = (string) ($_POST['tab'] ?? $tab);
    if (isset($TABS[$posted_tab])) {
        $tab = $posted_tab;
    }
}

layout_open('Settings');

$flash_success = flash_get('success');
if ($flash_success) {
    echo '<div class="alert alert-success" role="status">' . e($flash_success) . '</div>';
}
if ($errors) {
    echo '<div class="alert alert-error" role="alert"><ul>';
    foreach ($errors as $field_errs) {
        foreach ((array) $field_errs as $err) {
            echo '<li>' . e($err) . '</li>';
        }
    }
    echo '</ul></div>';
}

page_header('System Settings');

echo '<nav class="settings-tabs" aria-label="Settings sections">';
foreach ($TABS as $key => $label) {
    $is = $key === $tab;
    printf(
        '<a class="settings-tab%s" href="%s"%s>%s</a>',
        $is ? ' active' : '',
        e(url('/index.php?p=admin.settings&tab=' . $key)),
        $is ? ' aria-current="page"' : '',
        e($label)
    );
}
echo '</nav>';
?>

<?php if ($tab === 'scoring'): ?>
<section class="card" aria-labelledby="weights-title">
  <h2 class="card-title" id="weights-title">Match scoring weights</h2>
  <p class="card-subtitle">
    The five weights must total exactly <strong>100</strong>. They control how the
    system scores candidate matches between lost reports and found items.
  </p>

  <form method="POST" class="stack-4">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="match_weights">
    <input type="hidden" name="tab" value="scoring">

    <div class="notify-grid">
      <?php
        $weight_fields = [
            'match_weight_category'    => ['Category',    '30'],
            'match_weight_color'       => ['Color',       '20'],
            'match_weight_location'    => ['Location',    '15'],
            'match_weight_date'        => ['Date',        '10'],
            'match_weight_description' => ['Description', '25'],
        ];
        foreach ($weight_fields as $key => [$label, $default]):
      ?>
        <div class="form-group">
          <label for="<?= e($key) ?>" class="form-label"><?= e($label) ?></label>
          <?= field_error_html($key, $errors) ?>
          <input type="number" id="<?= e($key) ?>" name="<?= e($key) ?>"
                 value="<?= e($_POST[$key] ?? $setting($key, $default)) ?>"
                 min="0" max="100" class="form-control<?= !empty($errors[$key]) ? ' is-invalid' : '' ?>"<?= field_aria($key, $errors) ?>>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="form-group" style="max-width: 200px;">
      <label for="match_threshold" class="form-label">
        Match threshold
        <span class="form-hint">(minimum score to propose)</span>
      </label>
      <?= field_error_html('match_threshold', $errors) ?>
      <input type="number" id="match_threshold" name="match_threshold"
             value="<?= e($_POST['match_threshold'] ?? $setting('match_threshold', '30')) ?>"
             min="0" max="100" class="form-control<?= !empty($errors['match_threshold']) ? ' is-invalid' : '' ?>"<?= field_aria('match_threshold', $errors) ?>>
    </div>

    <button type="submit" class="btn btn-primary">Save weights</button>
  </form>
</section>

<?php elseif ($tab === 'holding'): ?>
<section class="card" aria-labelledby="holding-title">
  <h2 class="card-title" id="holding-title">Holding period</h2>
  <p class="card-subtitle">
    Number of days a found item is held before it is automatically marked
    <strong>EXPIRED</strong> by the daily expiry job.
  </p>

  <form method="POST" class="stack-4">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="holding_period">
    <input type="hidden" name="tab" value="holding">

    <div class="form-group" style="max-width: 200px;">
      <label for="holding_period_days" class="form-label">Days</label>
      <?= field_error_html('holding_period_days', $errors) ?>
      <input type="number" id="holding_period_days" name="holding_period_days"
             value="<?= e($_POST['holding_period_days'] ?? $setting('holding_period_days', '365')) ?>"
             min="1" max="365"
             class="form-control<?= !empty($errors['holding_period_days']) ? ' is-invalid' : '' ?>"<?= field_aria('holding_period_days', $errors) ?>>
    </div>

    <button type="submit" class="btn btn-primary">Save holding period</button>
  </form>
</section>

<?php elseif ($tab === 'storage'):
    $locations = q_all(
        'SELECT s.id, s.code, s.description, s.is_active,
                (SELECT COUNT(*) FROM found_reports f WHERE f.storage_location_id = s.id) AS item_count
           FROM storage_locations s
          ORDER BY s.is_active DESC, s.code'
    );

    // On an update validation error there is no ?edit in the (POST) URL, so
    // recover the edit context from the posted id.
    $edit_id = (int) ($_GET['edit'] ?? 0);
    if (($_POST['section'] ?? '') === 'storage_update' && $errors) {
        $edit_id = (int) ($_POST['id'] ?? 0);
    }
    $edit_row = $edit_id > 0 ? q_one('SELECT * FROM storage_locations WHERE id = ?', [$edit_id]) : null;

    $f_code = $_POST['code'] ?? ($edit_row['code'] ?? '');
    $f_desc = $_POST['description'] ?? ($edit_row['description'] ?? '');
?>
<section class="card" aria-labelledby="storage-list-title">
  <h2 class="card-title" id="storage-list-title">Storage locations</h2>
  <p class="card-subtitle">
    Bins and shelves where found items are held. Deactivating a location hides it
    from the “Log Found Item” dropdown but keeps it on existing records.
  </p>

  <?php if (!$locations): ?>
    <div class="empty-state">
      <p class="empty-state-title">No storage locations yet</p>
      <p>Add the first one using the form below.</p>
    </div>
  <?php else: ?>
    <div class="table-wrap table-wrap-cards">
      <table class="data-table data-table-static">
        <thead>
          <tr>
            <th>Code</th>
            <th>Description</th>
            <th class="col-narrow">Items</th>
            <th class="col-narrow">Status</th>
            <th class="col-narrow">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($locations as $loc): ?>
            <tr>
              <td data-label="Code" style="font-family: var(--font-family-mono);"><?= e((string) $loc['code']) ?></td>
              <td data-label="Description"><?= e((string) $loc['description']) ?></td>
              <td data-label="Items" class="col-narrow"><?= (int) $loc['item_count'] ?></td>
              <td data-label="Status" class="col-narrow">
                <?php if ($loc['is_active']): ?>
                  Active
                <?php else: ?>
                  <span class="text-muted">Inactive</span>
                <?php endif; ?>
              </td>
              <td data-label="Actions" class="col-narrow">
                <div style="display: flex; gap: var(--space-2); flex-wrap: wrap;">
                  <a class="btn btn-ghost btn-sm"
                     href="<?= e(url('/index.php?p=admin.settings&tab=storage&edit=' . (int) $loc['id'])) ?>">Edit</a>
                  <form method="POST" style="display: inline;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="section" value="storage_set_active">
                    <input type="hidden" name="tab" value="storage">
                    <input type="hidden" name="id" value="<?= (int) $loc['id'] ?>">
                    <input type="hidden" name="active" value="<?= $loc['is_active'] ? '0' : '1' ?>">
                    <button type="submit" class="btn btn-sm <?= $loc['is_active'] ? 'btn-warning' : 'btn-ghost' ?>">
                      <?= $loc['is_active'] ? 'Deactivate' : 'Activate' ?>
                    </button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<section class="card" aria-labelledby="storage-form-title">
  <h2 class="card-title" id="storage-form-title">
    <?= $edit_row ? 'Edit ' . e((string) $edit_row['code']) : 'Add a storage location' ?>
  </h2>
  <form method="POST" class="stack-4">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="<?= $edit_row ? 'storage_update' : 'storage_create' ?>">
    <input type="hidden" name="tab" value="storage">
    <?php if ($edit_row): ?>
      <input type="hidden" name="id" value="<?= (int) $edit_row['id'] ?>">
    <?php endif; ?>

    <div class="form-group" style="max-width: 280px;">
      <label for="code" class="form-label form-label-required">Code</label>
      <?= field_error_html('code', $errors) ?>
      <input type="text" id="code" name="code" maxlength="20"
             value="<?= e((string) $f_code) ?>"
             class="form-control<?= !empty($errors['code']) ? ' is-invalid' : '' ?>"<?= field_aria('code', $errors) ?>>
      <span class="form-hint">Short label, e.g. BIN-A-1 (max 20 characters).</span>
    </div>

    <div class="form-group" style="max-width: 480px;">
      <label for="description" class="form-label form-label-required">Description</label>
      <?= field_error_html('description', $errors) ?>
      <input type="text" id="description" name="description" maxlength="255"
             value="<?= e((string) $f_desc) ?>"
             class="form-control<?= !empty($errors['description']) ? ' is-invalid' : '' ?>"<?= field_aria('description', $errors) ?>>
    </div>

    <div style="display: flex; gap: var(--space-3);">
      <button type="submit" class="btn btn-primary">
        <?= $edit_row ? 'Save changes' : 'Add location' ?>
      </button>
      <?php if ($edit_row): ?>
        <a class="btn btn-ghost" href="<?= e(url('/index.php?p=admin.settings&tab=storage')) ?>">Cancel</a>
      <?php endif; ?>
    </div>
  </form>
</section>

<?php elseif ($tab === 'backup'):
    $last_backup = (string) $setting('last_backup_at', '');
?>
<section class="card" aria-labelledby="backup-title">
  <h2 class="card-title" id="backup-title">Backup status</h2>
  <p class="card-subtitle">
    Timestamp of the last automatic MySQL backup, written by the scheduled
    backup job.
  </p>
  <dl class="detail-grid">
    <dt>Last backup</dt>
    <dd>
      <?php if ($last_backup !== ''): ?>
        <?= e(date('F j, Y g:i A', strtotime($last_backup))) ?>
      <?php else: ?>
        <span class="text-muted">No automatic backup has been recorded yet.</span>
      <?php endif; ?>
    </dd>
  </dl>
  <p class="text-sm text-muted">
    The backup procedure itself is part of operations setup (project roadmap §4).
    This panel reads the <code>last_backup_at</code> setting.
  </p>
</section>

<?php elseif ($tab === 'users'):
    $u_state = table_state('', ['sort' => 'name', 'dir' => 'asc', 'per_page' => 25]);
    $u_role  = trim((string) ($_GET['role'] ?? ''));
    $role_labels = ['user' => 'Student/Faculty', 'staff' => 'Staff', 'admin' => 'Admin'];

    $u_where  = [];
    $u_params = [];
    if ($u_state['q'] !== '') {
        $u_where[]  = '(full_name LIKE ? OR email LIKE ? OR id_number LIKE ?)';
        $like = '%' . $u_state['q'] . '%';
        $u_params[] = $like; $u_params[] = $like; $u_params[] = $like;
    }
    if (isset($role_labels[$u_role])) {
        $u_where[]  = 'role = ?';
        $u_params[] = $u_role;
    }
    $u_where_sql = $u_where ? 'WHERE ' . implode(' AND ', $u_where) : '';

    $u_total  = (int) (q_value('SELECT COUNT(*) FROM accounts ' . $u_where_sql, $u_params) ?? 0);
    $u_offset = ($u_state['page'] - 1) * $u_state['per_page'];
    $u_rows   = q_all(
        'SELECT id, role, full_name, id_number, email, is_active
           FROM accounts ' . $u_where_sql . '
          ORDER BY full_name ASC
          LIMIT ? OFFSET ?',
        array_merge($u_params, [$u_state['per_page'], $u_offset])
    );

    $u_base = array_filter([
        'p'        => 'admin.settings',
        'tab'      => 'users',
        'q'        => $u_state['q'],
        'role'     => isset($role_labels[$u_role]) ? $u_role : '',
        'per_page' => $u_state['per_page'] !== 25 ? (string) $u_state['per_page'] : '',
    ], static fn($v) => $v !== '' && $v !== null);
?>
<section class="card" aria-labelledby="users-title">
  <h2 class="card-title" id="users-title">Users &amp; roles</h2>
  <p class="card-subtitle">
    Change a member's role or deactivate their access. You cannot change your own
    role or remove the last active administrator.
  </p>

  <form method="GET" class="filter-bar" style="flex-wrap: wrap; gap: var(--space-3);">
    <input type="hidden" name="p" value="admin.settings">
    <input type="hidden" name="tab" value="users">
    <input type="search" name="q" value="<?= e($u_state['q']) ?>"
           placeholder="Name, email, ID number&hellip;"
           class="form-control" style="flex: 1; min-width: 180px;" aria-label="Search accounts">
    <select name="role" class="form-control" style="width: auto;" aria-label="Filter by role">
      <option value="">All roles</option>
      <?php foreach ($role_labels as $rv => $rl): ?>
        <option value="<?= e($rv) ?>"<?= $u_role === $rv ? ' selected' : '' ?>><?= e($rl) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-ghost btn-sm">Filter</button>
    <a href="<?= e(url('/index.php?p=admin.settings&tab=users')) ?>" class="btn btn-ghost btn-sm">Reset</a>
  </form>

  <?php if (!$u_rows): ?>
    <div class="empty-state">
      <p class="empty-state-title">No accounts match the current filters</p>
    </div>
  <?php else: ?>
    <div class="table-wrap table-wrap-cards">
      <table class="data-table data-table-static">
        <thead>
          <tr>
            <th>Name</th>
            <th>ID number</th>
            <th>Role</th>
            <th class="col-narrow">Status</th>
            <th class="col-narrow">Access</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($u_rows as $acct): $is_self = (int) $acct['id'] === $user_id; ?>
            <tr>
              <td data-label="Name">
                <?= e((string) $acct['full_name']) ?>
                <?php if ($is_self): ?><span class="text-sm text-muted">(you)</span><?php endif; ?>
                <div class="text-sm text-muted"><?= e((string) $acct['email']) ?></div>
              </td>
              <td data-label="ID number" style="font-family: var(--font-family-mono);"><?= e((string) $acct['id_number']) ?></td>
              <td data-label="Role">
                <?php if ($is_self): ?>
                  <?= e($role_labels[(string) $acct['role']] ?? strtoupper((string) $acct['role'])) ?>
                <?php else: ?>
                  <form method="POST" style="display: flex; gap: var(--space-2); align-items: center;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="section" value="user_set_role">
                    <input type="hidden" name="tab" value="users">
                    <input type="hidden" name="id" value="<?= (int) $acct['id'] ?>">
                    <label class="sr-only" for="role-<?= (int) $acct['id'] ?>">Role for <?= e((string) $acct['full_name']) ?></label>
                    <select id="role-<?= (int) $acct['id'] ?>" name="new_role" class="form-control" style="width: auto;">
                      <?php foreach ($role_labels as $rv => $rl): ?>
                        <option value="<?= e($rv) ?>"<?= $acct['role'] === $rv ? ' selected' : '' ?>><?= e($rl) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-ghost btn-sm">Save</button>
                  </form>
                <?php endif; ?>
              </td>
              <td data-label="Status" class="col-narrow">
                <?php if ($acct['is_active']): ?>Active<?php else: ?><span class="text-muted">Inactive</span><?php endif; ?>
              </td>
              <td data-label="Access" class="col-narrow">
                <?php if ($is_self): ?>
                  <span class="text-muted text-sm">&mdash;</span>
                <?php else: ?>
                  <form method="POST" style="display: inline;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="section" value="user_set_active">
                    <input type="hidden" name="tab" value="users">
                    <input type="hidden" name="id" value="<?= (int) $acct['id'] ?>">
                    <input type="hidden" name="active" value="<?= $acct['is_active'] ? '0' : '1' ?>">
                    <button type="submit" class="btn btn-sm <?= $acct['is_active'] ? 'btn-warning' : 'btn-ghost' ?>"><?= $acct['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= render_pagination($u_total, $u_state, '', $u_base) ?>
  <?php endif; ?>
</section>

<section class="card" aria-labelledby="create-user-title">
  <h2 class="card-title" id="create-user-title">Create account</h2>
  <p class="card-subtitle">
    Create an account directly — useful for onboarding library staff without
    requiring self-registration. Staff and Admin roles skip the student ID
    format check.
  </p>

  <form method="POST" class="stack-4" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="user_create">
    <input type="hidden" name="tab" value="users">

    <div class="field<?= isset($errors['full_name']) ? ' field-error' : '' ?>">
      <label class="field-label field-label-required" for="c_full_name">Full name</label>
      <input type="text" id="c_full_name" name="full_name" class="field-input"
             value="<?= e($_POST['full_name'] ?? '') ?>"
             autocomplete="off"<?= field_aria('full_name', $errors) ?>>
      <?= field_error_html('full_name', $errors) ?>
    </div>

    <div class="field<?= isset($errors['role']) ? ' field-error' : '' ?>">
      <label class="field-label field-label-required" for="c_role">Role</label>
      <select id="c_role" name="role" class="field-input"<?= field_aria('role', $errors) ?>>
        <option value="">— select role —</option>
        <?php foreach ($role_labels as $rv => $rl): ?>
          <option value="<?= e($rv) ?>"<?= ($_POST['role'] ?? '') === $rv ? ' selected' : '' ?>><?= e($rl) ?></option>
        <?php endforeach; ?>
      </select>
      <?= field_error_html('role', $errors) ?>
    </div>

    <div class="field<?= isset($errors['user_type']) ? ' field-error' : '' ?>">
      <span class="field-label">Account type
        <span class="text-muted text-sm">(required for Student/Faculty role only)</span>
      </span>
      <div class="radio-group" role="radiogroup" aria-label="Account type"<?= field_aria('user_type', $errors) ?>>
        <label class="radio-option">
          <input type="radio" name="user_type" value="student"
                 <?= ($_POST['user_type'] ?? 'student') === 'student' ? 'checked' : '' ?>>
          <span>Student</span>
        </label>
        <label class="radio-option">
          <input type="radio" name="user_type" value="faculty"
                 <?= ($_POST['user_type'] ?? '') === 'faculty' ? 'checked' : '' ?>>
          <span>Faculty</span>
        </label>
      </div>
      <?= field_error_html('user_type', $errors) ?>
    </div>

    <div class="field<?= isset($errors['id_number']) ? ' field-error' : '' ?>">
      <label class="field-label field-label-required" for="c_id_number">Student / Employee number</label>
      <input type="text" id="c_id_number" name="id_number" class="field-input"
             value="<?= e($_POST['id_number'] ?? '') ?>"
             autocomplete="off"<?= field_aria('id_number', $errors) ?>>
      <p class="field-helper">
        Students: <code>2024-00001</code> &nbsp;&middot;&nbsp;
        Faculty: <code>2020-EMP-001</code> &nbsp;&middot;&nbsp;
        Staff/Admin: any format.
      </p>
      <?= field_error_html('id_number', $errors) ?>
    </div>

    <div class="field<?= isset($errors['email']) ? ' field-error' : '' ?>">
      <label class="field-label field-label-required" for="c_email">Email</label>
      <input type="email" id="c_email" name="email" class="field-input"
             value="<?= e($_POST['email'] ?? '') ?>"
             autocomplete="off"<?= field_aria('email', $errors) ?>>
      <?= field_error_html('email', $errors) ?>
    </div>

    <div class="field<?= isset($errors['password']) ? ' field-error' : '' ?>">
      <label class="field-label field-label-required" for="c_password">Password</label>
      <input type="password" id="c_password" name="password" class="field-input"
             autocomplete="new-password"<?= field_aria('password', $errors) ?>>
      <p class="field-helper">At least 8 characters. Share it with the account holder so they can sign in.</p>
      <?= field_error_html('password', $errors) ?>
    </div>

    <div class="field">
      <label class="field-label field-label-required" for="c_password_confirm">Confirm password</label>
      <input type="password" id="c_password_confirm" name="password_confirm" class="field-input"
             autocomplete="new-password">
    </div>

    <div>
      <button type="submit" class="btn btn-primary">Create account</button>
    </div>
  </form>
</section>

<?php elseif ($tab === 'notifications'): ?>
<section class="card" aria-labelledby="notif-title">
  <h2 class="card-title" id="notif-title">Notification rules</h2>
  <p class="card-subtitle">
    Choose which events send an in-app notification to the affected member.
    Everything is on by default.
  </p>
  <form method="POST" class="stack-4">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="notification_rules">
    <input type="hidden" name="tab" value="notifications">
    <div class="check-list">
      <?php foreach (notify_event_types() as $type => $label): ?>
        <label class="check-option">
          <input type="checkbox" name="notify[<?= e($type) ?>]" value="1"<?= notify_enabled($type) ? ' checked' : '' ?>>
          <span><?= e($label) ?></span>
        </label>
      <?php endforeach; ?>
    </div>
    <button type="submit" class="btn btn-primary">Save notification rules</button>
  </form>
</section>
<?php endif; ?>

<?php
layout_close();
