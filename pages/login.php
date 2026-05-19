<?php
declare(strict_types=1);

/**
 * Login page — email + password sign-in.
 *
 * GET:  Renders the form. Restores any flashed errors / old email / info banner.
 * POST: Validates input, looks up the account, verifies the password, logs the user in,
 *       and redirects to ?next= if it points to a same-origin URL, otherwise to the
 *       role-appropriate dashboard.
 *
 * Failure path always uses POST-Redirect-GET so that refreshing the result page
 * never re-submits credentials.
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $email    = trim((string) ($_POST['email']    ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $next     = (string) ($_POST['next']     ?? '');

    $errors = validate(
        ['email' => $email, 'password' => $password],
        [
            'email'    => 'required|email|max:255',
            'password' => 'required|max:255',
        ]
    );

    if (!$errors) {
        $account = q_one(
            'SELECT id, role, full_name, email, password_hash, is_active
               FROM accounts
              WHERE email = ?
              LIMIT 1',
            [$email]
        );

        if (!$account || !password_matches($password, $account['password_hash'])) {
            // Generic failure message — never reveal which field was wrong.
            $errors['_form'] = ['Invalid email or password.'];
        } elseif (!$account['is_active']) {
            $errors['_form'] = ['This account is deactivated. Contact the library administrator.'];
        } else {
            login_user($account);
            $dest = is_safe_local_url($next) ? $next : url('/index.php');
            go($dest);
        }
    }

    back([
        'errors' => $errors,
        'old'    => ['email' => $email],
        'next'   => $next,
    ]);
}

// ---- GET ----

$errors = flash_get('errors') ?: [];
$old    = flash_get('old')    ?: [];
$info   = flash_get('info');
$next   = (string) ($_GET['next'] ?? flash_get('next') ?? '');

auth_card_open(
    'Sign in to LFMS',
    'Enter your FEU email and password to continue.'
);
?>

  <?php if ($info): ?>
    <div class="alert alert-info" role="status"><?= e($info) ?></div>
  <?php endif; ?>

  <?php if (!empty($errors['_form'])): ?>
    <div class="alert alert-danger" role="alert"><?= e($errors['_form'][0]) ?></div>
  <?php endif; ?>

  <form method="POST" action="<?= e(url('/index.php?p=login')) ?>" data-validate novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="next" value="<?= e($next) ?>">

    <div class="field<?= isset($errors['email']) ? ' field-error' : '' ?>">
      <label class="field-label field-label-required" for="email">Email</label>
      <input type="email"
             id="email"
             name="email"
             class="field-input"
             value="<?= e($old['email'] ?? '') ?>"
             autocomplete="username"
             data-rule="required|email|max:255"
             autofocus
             required<?= field_aria('email', $errors) ?>>
      <?= field_error_html('email', $errors, 'field-error-text') ?>
    </div>

    <div class="field<?= isset($errors['password']) ? ' field-error' : '' ?>">
      <label class="field-label field-label-required" for="password">Password</label>
      <input type="password"
             id="password"
             name="password"
             class="field-input"
             autocomplete="current-password"
             data-rule="required|max:255"
             required<?= field_aria('password', $errors) ?>>
      <?= field_error_html('password', $errors, 'field-error-text') ?>
    </div>

    <button type="submit" class="btn btn-primary btn-block btn-lg">Sign in</button>

    <p class="auth-card-footer-link">
      <a href="<?= e(url('/index.php?p=forgot')) ?>">Forgot password?</a>
    </p>
  </form>

  <div class="auth-card-divider"></div>

  <p class="auth-card-footer">
    Don&rsquo;t have an account?
    <a href="<?= e(url('/index.php?p=register')) ?>">Create one</a>
  </p>

<?php
auth_card_close();
