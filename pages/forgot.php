<?php
declare(strict_types=1);

/**
 * Forgot password — v1 placeholder.
 *
 * Self-service password reset is not in v1 scope (no SMTP on the LAN). Users
 * are directed to visit the library counter for an in-person reset. The
 * library administrator can reset a password directly in the database, or
 * via the Admin → Users tab once that ships in Task 19.
 */

auth_card_open(
    'Forgot your password?',
    'Self-service reset is not available yet. Here is what to do instead.'
);
?>

  <p class="text-muted">
    Please visit the FEU Library Lost &amp; Found counter during operating hours
    (Mon&ndash;Sat, 8:00am&ndash;5:00pm). Bring your school ID or certificate of
    enrollment. A library administrator will verify your identity and reset
    your password manually.
  </p>

  <p class="text-muted text-sm mt-4">
    Self-service password reset is planned for a future release.
  </p>

  <div class="auth-card-divider"></div>

  <p class="auth-card-footer">
    <a href="<?= e(url('/index.php?p=login')) ?>">Back to sign in</a>
  </p>

<?php
auth_card_close();
