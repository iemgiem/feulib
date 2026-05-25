<?php
declare(strict_types=1);

/**
 * Authentication + session lifecycle + role gating.
 *
 *   session_boot()          Start the PHP session with safe cookie params.
 *   current_user()          Return the row from `accounts` for the logged-in user, or null.
 *   is_authenticated()      Boolean shortcut.
 *   user_role()             'user' | 'staff' | 'admin' | null.
 *   has_role($roles)        Match against a single role or array of roles.
 *   require_login()         Redirect to /login (preserving the `next` URL) if anonymous.
 *   require_role($roles)    Redirect to 403 if the user does not match the given role(s).
 *   login_user($account)    Establish a new session for the given account row.
 *   logout_user()           Tear down the session.
 *
 * The session cookie carries HttpOnly + SameSite=Strict by default. Session ID
 * is regenerated on every successful login to defeat session-fixation.
 */

/**
 * Start the PHP session using cookie params from config.
 * Safe to call multiple times — no-op if a session is already active.
 */
function session_boot(): void
{
    if (PHP_SAPI === 'cli') {
        // No HTTP context — leave $_SESSION uninitialised. Audit_log() and
        // current_user() already handle the missing session gracefully, which
        // is exactly what CLI tools like db/expire_items.php depend on.
        return;
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $cfg = cfg('session') ?? [];

    session_name($cfg['name'] ?? 'LFMSSESSID');
    session_set_cookie_params([
        'lifetime' => $cfg['lifetime']        ?? 0,
        'path'     => '/',
        'secure'   => $cfg['cookie_secure']   ?? false,
        'httponly' => $cfg['cookie_httponly'] ?? true,
        'samesite' => $cfg['cookie_samesite'] ?? 'Strict',
    ]);
    session_start();
}

/**
 * Return the logged-in account row, or null if anonymous or deactivated.
 * Caches the row per request.
 */
function current_user(): ?array
{
    static $cached;
    if ($cached !== null) {
        return $cached === false ? null : $cached;
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        // Caller invoked us before session_boot — don't cache that absence.
        return null;
    }
    if (empty($_SESSION['user_id'])) {
        $cached = false;
        return null;
    }
    $row = q_one(
        'SELECT id, role, user_type, full_name, id_number, email, is_active, created_at
           FROM accounts
          WHERE id = ? AND is_active = 1',
        [(int) $_SESSION['user_id']]
    );
    if ($row === null) {
        // Session points to a missing or deactivated account — drop the session.
        $_SESSION = [];
        $cached = false;
        return null;
    }
    $cached = $row;
    return $row;
}

function is_authenticated(): bool
{
    return current_user() !== null;
}

function user_role(): ?string
{
    $u = current_user();
    return $u['role'] ?? null;
}

/**
 * @param string|string[] $roles
 */
function has_role($roles): bool
{
    $role = user_role();
    if ($role === null) {
        return false;
    }
    return is_array($roles) ? in_array($role, $roles, true) : $role === $roles;
}

/**
 * Redirect to the login page if the user is anonymous. Preserves the target
 * URL via ?next= so login can return them after authentication.
 */
function require_login(): void
{
    if (!is_authenticated()) {
        $next = urlencode($_SERVER['REQUEST_URI'] ?? '/index.php?p=dashboard');
        go('/index.php?p=login&next=' . $next);
    }
}

/**
 * Require any of the given roles. Falls through to 403 on mismatch.
 *
 * @param string|string[] $roles
 */
function require_role($roles): void
{
    require_login();
    if (!has_role($roles)) {
        go('/index.php?p=403');
    }
}

/**
 * Establish a logged-in session for the given account row.
 * Regenerates the session ID; writes an audit entry.
 */
function login_user(array $account): void
{
    session_regenerate_id(true);
    $_SESSION['user_id']   = (int) $account['id'];
    $_SESSION['user_role'] = $account['role'];
    audit_log('auth.login', 'account', (int) $account['id']);
}

/**
 * End the current session. Writes an audit entry first while the user id
 * is still known.
 */
function logout_user(): void
{
    if (!empty($_SESSION['user_id'])) {
        audit_log('auth.logout', 'account', (int) $_SESSION['user_id']);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path']     ?: '/',
            $params['domain']   ?? '',
            $params['secure']   ?? false,
            $params['httponly'] ?? true
        );
    }
    session_destroy();
}

/**
 * Verify a plaintext password against the bcrypt hash stored on the account.
 * Wraps password_verify so the lookup site stays terse.
 */
function password_matches(string $plaintext, string $stored_hash): bool
{
    return password_verify($plaintext, $stored_hash);
}
