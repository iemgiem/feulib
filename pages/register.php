<?php
declare(strict_types=1);

/**
 * Register page — self-service signup for students and faculty.
 *
 * GET:  Renders the form (restores old values on validation failure; never passwords).
 * POST: Validates, checks email/id uniqueness, hashes the password, inserts the
 *       account with role='user', auto-logs the new user in, and redirects to
 *       the user dashboard with a welcome banner.
 *
 * Format guidance (server-side):
 *   user_type = student  → id_number must match /^\d{4}-\d{4,8}$/
 *   user_type = faculty  → id_number must match /^\d{4}-EMP-\d{1,6}$/
 *
 * Format guidance is enforced and surfaced as inline error messages.
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $full_name   = clean((string) ($_POST['full_name']   ?? ''));
    $user_type   = (string) ($_POST['user_type']   ?? '');
    $id_number   = clean_id((string) ($_POST['id_number']   ?? ''));
    $email       = trim((string) ($_POST['email']       ?? ''));
    $password    = (string) ($_POST['password']    ?? '');
    $password_confirm = (string) ($_POST['password_confirm'] ?? '');

    // Base rule set
    $errors = validate(
        [
            'full_name'        => $full_name,
            'user_type'        => $user_type,
            'id_number'        => $id_number,
            'email'            => $email,
            'password'         => $password,
            'password_confirm' => $password_confirm,
        ],
        [
            'full_name' => 'required|min:2|max:150',
            'user_type' => 'required|enum:student,faculty',
            'id_number' => 'required|max:50',
            'email'     => 'required|email|max:255',
            'password'  => 'required|min:8|max:255|confirmed',
        ]
    );

    // Type-specific ID-number format check (only run when user_type is valid)
    if (!isset($errors['user_type']) && !isset($errors['id_number'])) {
        $pattern = $user_type === 'faculty'
            ? '/^\d{4}-EMP-\d{1,6}$/'
            : '/^\d{4}-\d{4,8}$/';
        if (!preg_match($pattern, $id_number)) {
            $errors['id_number'][] = $user_type === 'faculty'
                ? 'Faculty IDs look like 2020-EMP-001.'
                : 'Student numbers look like 2024-00001.';
        }
    }

    // Uniqueness checks — only when the value has passed format validation
    if (!isset($errors['email'])) {
        $existing = q_value('SELECT 1 FROM accounts WHERE email = ? LIMIT 1', [$email]);
        if ($existing) {
            $errors['email'][] = 'This email is already registered. Try signing in instead.';
        }
    }
    if (!isset($errors['id_number'])) {
        $existing = q_value('SELECT 1 FROM accounts WHERE id_number = ? LIMIT 1', [$id_number]);
        if ($existing) {
            $errors['id_number'][] = 'This ID number is already registered.';
        }
    }

    if (!$errors) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        db_transaction(function () use ($full_name, $user_type, $id_number, $email, $hash) {
            q(
                'INSERT INTO accounts (role, user_type, full_name, id_number, email, password_hash, is_active)
                 VALUES (\'user\', ?, ?, ?, ?, ?, 1)',
                [$user_type, $full_name, $id_number, $email, $hash]
            );
            $new_id = db_last_id();
            audit_log('account.register', 'account', $new_id, [
                'role'      => 'user',
                'user_type' => $user_type,
            ]);
            $_SESSION['_new_account_id'] = $new_id;
        });

        $account = q_one('SELECT * FROM accounts WHERE id = ?', [$_SESSION['_new_account_id']]);
        unset($_SESSION['_new_account_id']);
        login_user($account);

        flash_set('success', 'Welcome to FEU Library Lost & Found, ' . $full_name . '.');
        go(url('/index.php?p=dashboard'));
    }

    // Validation failed — bounce back with errors + safe-to-restore values.
    back([
        'errors' => $errors,
        'old'    => [
            'full_name' => $full_name,
            'user_type' => $user_type,
            'id_number' => $id_number,
            'email'     => $email,
            // never re-flash the password
        ],
    ]);
}

// ---- GET ----

$errors = flash_get('errors') ?: [];
$old    = flash_get('old')    ?: [];

auth_card_open(
    'Create your LFMS account',
    'For currently enrolled students and active faculty of FEU Manila.'
);
?>

  <?php if (!empty($errors['_form'])): ?>
    <div class="alert alert-danger" role="alert"><?= e($errors['_form'][0]) ?></div>
  <?php endif; ?>

  <form method="POST" action="<?= e(url('/index.php?p=register')) ?>" data-validate novalidate>
    <?= csrf_field() ?>

    <div class="field<?= isset($errors['full_name']) ? ' field-error' : '' ?>">
      <label class="field-label field-label-required" for="full_name">Full name</label>
      <input type="text" id="full_name" name="full_name" class="field-input"
             value="<?= e($old['full_name'] ?? '') ?>"
             autocomplete="name"
             data-rule="required|min:2|max:150"
             autofocus required<?= field_aria('full_name', $errors) ?>>
      <?= field_error_html('full_name', $errors, 'field-error-text') ?>
    </div>

    <div class="field<?= isset($errors['user_type']) ? ' field-error' : '' ?>">
      <span class="field-label field-label-required">I am a</span>
      <div class="radio-group" role="radiogroup" aria-label="Account type"<?= field_aria('user_type', $errors) ?>>
        <label class="radio-option">
          <input type="radio" name="user_type" value="student"
                 data-rule="required|enum:student,faculty"
                 <?= ($old['user_type'] ?? 'student') === 'student' ? 'checked' : '' ?>>
          <span>Student</span>
        </label>
        <label class="radio-option">
          <input type="radio" name="user_type" value="faculty"
                 <?= ($old['user_type'] ?? '') === 'faculty' ? 'checked' : '' ?>>
          <span>Faculty</span>
        </label>
      </div>
      <?= field_error_html('user_type', $errors, 'field-error-text') ?>
    </div>

    <div class="field<?= isset($errors['id_number']) ? ' field-error' : '' ?>">
      <label class="field-label field-label-required" for="id_number">Student / Employee number</label>
      <input type="text" id="id_number" name="id_number" class="field-input"
             value="<?= e($old['id_number'] ?? '') ?>"
             data-rule="required|max:50"
             required<?= field_aria('id_number', $errors) ?>>
      <p class="field-helper">Format: <code>2024-00001</code> for students, <code>2020-EMP-001</code> for faculty.</p>
      <?= field_error_html('id_number', $errors, 'field-error-text') ?>
    </div>

    <div class="field<?= isset($errors['email']) ? ' field-error' : '' ?>">
      <label class="field-label field-label-required" for="email">FEU email</label>
      <input type="email" id="email" name="email" class="field-input"
             value="<?= e($old['email'] ?? '') ?>"
             autocomplete="email"
             data-rule="required|email|max:255"
             required<?= field_aria('email', $errors) ?>>
      <?= field_error_html('email', $errors, 'field-error-text') ?>
    </div>

    <div class="field<?= isset($errors['password']) ? ' field-error' : '' ?>">
      <label class="field-label field-label-required" for="password">Password</label>
      <input type="password" id="password" name="password" class="field-input"
             autocomplete="new-password"
             data-rule="required|min:8|max:255|confirmed"
             required<?= field_aria('password', $errors) ?>>
      <p class="field-helper">At least 8 characters.</p>
      <?= field_error_html('password', $errors, 'field-error-text') ?>
    </div>

    <div class="field">
      <label class="field-label field-label-required" for="password_confirm">Confirm password</label>
      <input type="password" id="password_confirm" name="password_confirm" class="field-input"
             autocomplete="new-password"
             required>
    </div>

    <button type="submit" class="btn btn-primary btn-block btn-lg">Create account</button>
  </form>

  <div class="auth-card-divider"></div>

  <p class="auth-card-footer">
    Already have an account?
    <a href="<?= e(url('/index.php?p=login')) ?>">Sign in</a>
  </p>

<?php
auth_card_close();
