<?php
declare(strict_types=1);

/**
 * User profile — view and update display name + password.
 *
 * GET  ?p=profile         Show profile form.
 * POST ?p=profile         Update name and/or password.
 */

$session_user = current_user();
$user_id      = (int) $session_user['id'];

// current_user() omits password_hash; fetch the full row so the change-password
// flow can verify the current password.
$user = q_one('SELECT * FROM accounts WHERE id = ?', [$user_id]) ?? $session_user;

$errors = [];
$old    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action = $_POST['action'] ?? '';

    if ($action === 'update_name') {
        $full_name = trim($_POST['full_name'] ?? '');
        $old['full_name'] = $full_name;

        if ($full_name === '') {
            $errors['full_name'] = ['Full name is required.'];
        } elseif (mb_strlen($full_name) > 150) {
            $errors['full_name'] = ['Full name must be 150 characters or less.'];
        }

        if (!$errors) {
            q('UPDATE accounts SET full_name = ?, updated_at = NOW() WHERE id = ?',
                [$full_name, $user_id]);
            audit_log('account.update_name', 'account', $user_id, [
                'full_name' => [$user['full_name'], $full_name],
            ]);
            flash_set('success', 'Name updated.');
            go(url('/index.php?p=profile'));
        }
    } elseif ($action === 'change_password') {
        $current_pw  = $_POST['current_password']  ?? '';
        $new_pw      = $_POST['new_password']       ?? '';
        $confirm_pw  = $_POST['confirm_password']   ?? '';

        if (!password_verify($current_pw, (string) $user['password_hash'])) {
            $errors['current_password'] = ['Current password is incorrect.'];
        }
        if (strlen($new_pw) < 8) {
            $errors['new_password'] = ['New password must be at least 8 characters.'];
        }
        if ($new_pw !== $confirm_pw) {
            $errors['confirm_password'] = ['Passwords do not match.'];
        }

        if (!$errors) {
            $hash = password_hash($new_pw, PASSWORD_BCRYPT);
            q('UPDATE accounts SET password_hash = ?, updated_at = NOW() WHERE id = ?',
                [$hash, $user_id]);
            audit_log('account.change_password', 'account', $user_id);
            flash_set('success', 'Password changed successfully.');
            go(url('/index.php?p=profile'));
        }
    }
}

// Re-read user after any successful update
$user = q_one('SELECT * FROM accounts WHERE id = ?', [$user_id]) ?? $user;

layout_open('My Profile');

$success = flash_get('success');
if ($success) {
    echo '<div class="alert alert-success" role="status">' . e($success) . '</div>';
}

page_header('My Profile');
?>

<div class="compare-grid">

  <!-- Account info -->
  <section class="card" aria-labelledby="account-title">
    <h2 class="card-title" id="account-title">Account information</h2>

    <dl class="detail-grid" style="margin-bottom: var(--space-5);">
      <dt>Email</dt>
      <dd><?= e((string) $user['email']) ?></dd>

      <dt>ID number</dt>
      <dd><?= e((string) $user['id_number']) ?></dd>

      <dt>Role</dt>
      <dd><?= e(ucfirst((string) $user['role'])) ?></dd>

      <?php if (!empty($user['user_type'])): ?>
        <dt>Type</dt>
        <dd><?= e(ucfirst((string) $user['user_type'])) ?></dd>
      <?php endif; ?>

      <dt>Member since</dt>
      <dd><?= e(date('F j, Y', strtotime((string) $user['created_at']))) ?></dd>
    </dl>

    <h3 class="card-title" style="font-size: var(--font-size-base);">Update display name</h3>

    <?php if (!empty($errors['full_name'])): ?>
      <div class="alert alert-error" role="alert"><?= e($errors['full_name'][0]) ?></div>
    <?php endif; ?>

    <form method="POST" class="stack-4">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update_name">

      <div class="field<?= !empty($errors['full_name']) ? ' field-error' : '' ?>">
        <label for="full_name" class="field-label field-label-required">Full name</label>
        <input type="text" id="full_name" name="full_name"
               value="<?= e($old['full_name'] ?? (string) $user['full_name']) ?>"
               class="field-input"
               maxlength="150" required<?= field_aria('full_name', $errors) ?>>
        <?= field_error_html('full_name', $errors) ?>
      </div>

      <button type="submit" class="btn btn-primary">Update name</button>
    </form>
  </section>

  <!-- Change password -->
  <section class="card" aria-labelledby="pw-title">
    <h2 class="card-title" id="pw-title">Change password</h2>

    <?php if (!empty($errors['current_password']) || !empty($errors['new_password']) || !empty($errors['confirm_password'])): ?>
      <div class="alert alert-error" role="alert">
        <ul>
          <?php foreach (['current_password', 'new_password', 'confirm_password'] as $f):
            if (!empty($errors[$f])): ?>
              <li><?= e($errors[$f][0]) ?></li>
            <?php endif;
          endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form method="POST" class="stack-4">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="change_password">

      <div class="field<?= !empty($errors['current_password']) ? ' field-error' : '' ?>">
        <label for="current_password" class="field-label field-label-required">Current password</label>
        <input type="password" id="current_password" name="current_password"
               class="field-input"
               autocomplete="current-password" required<?= field_aria('current_password', $errors) ?>>
        <?= field_error_html('current_password', $errors) ?>
      </div>

      <div class="field<?= !empty($errors['new_password']) ? ' field-error' : '' ?>">
        <label for="new_password" class="field-label field-label-required">New password</label>
        <input type="password" id="new_password" name="new_password"
               class="field-input"
               autocomplete="new-password" minlength="8" required<?= field_aria('new_password', $errors) ?>>
        <span class="field-helper">Minimum 8 characters.</span>
        <?= field_error_html('new_password', $errors) ?>
      </div>

      <div class="field<?= !empty($errors['confirm_password']) ? ' field-error' : '' ?>">
        <label for="confirm_password" class="field-label field-label-required">Confirm new password</label>
        <input type="password" id="confirm_password" name="confirm_password"
               class="field-input"
               autocomplete="new-password" minlength="8" required<?= field_aria('confirm_password', $errors) ?>>
        <?= field_error_html('confirm_password', $errors) ?>
      </div>

      <button type="submit" class="btn btn-primary">Change password</button>
    </form>
  </section>

</div>

<?php
layout_close();
