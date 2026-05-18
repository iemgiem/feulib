<?php
declare(strict_types=1);

/**
 * Front controller — single entry point for every request.
 *
 * Responsibilities (in order):
 *   1. Load the lib/ toolkit and start the session.
 *   2. Resolve the requested page token from ?p=<token>.
 *   3. Reject unknown or malformed tokens with a 404.
 *   4. Enforce auth + role gates from lib/routes.php.
 *   5. Dispatch to the matching pages/<token>.php file.
 *
 * No code outside this file should be reachable directly by an end user — the
 * Apache config + Deny rules in /assets/uploads/.htaccess take care of that.
 */

require_once __DIR__ . '/lib/bootstrap.php';

$routes = require __DIR__ . '/lib/routes.php';

// -----------------------------------------------------------------------------
// 1. Resolve the requested token (and apply default-routing rules)
// -----------------------------------------------------------------------------

$token = isset($_GET['p']) && is_string($_GET['p']) ? trim($_GET['p']) : '';

if ($token === '') {
    // No token given → redirect to the role-appropriate landing page.
    go(url('/index.php?p=' . default_route_for_role(user_role())));
}

// Strict format guard — alphanumeric, dots, underscores. No slashes, no traversal.
if (!preg_match('/^[a-z0-9_.]+$/i', $token)) {
    render_error(404, $routes);
}

// Allow-list lookup.
if (!isset($routes[$token])) {
    render_error(404, $routes);
}

$route = $routes[$token];

// -----------------------------------------------------------------------------
// 2. Already-logged-in user shouldn't see the login / register pages
// -----------------------------------------------------------------------------

if (in_array($token, ['login', 'register'], true) && is_authenticated()) {
    go(url('/index.php?p=' . default_route_for_role(user_role())));
}

// -----------------------------------------------------------------------------
// 3. Auth + role gate
// -----------------------------------------------------------------------------

if (empty($route['public'])) {
    if (!is_authenticated()) {
        // Preserve the originally-requested URL so login can return the user
        // there after success.
        $next = $_SERVER['REQUEST_URI'] ?? '/index.php';
        go(url('/index.php?p=login&next=' . urlencode($next)));
    }
    if (!empty($route['roles']) && !has_role($route['roles'])) {
        render_error(403, $routes);
    }
}

// -----------------------------------------------------------------------------
// 4. Dispatch — include the matching page file
// -----------------------------------------------------------------------------

$page_file = __DIR__ . '/pages/' . $route['file'];

if (!file_exists($page_file)) {
    // Mapped in routes.php but the file is missing — developer error.
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    if (cfg('app.env') === 'development') {
        echo '<!DOCTYPE html><html><body style="font-family:Segoe UI,sans-serif;padding:32px;max-width:720px">';
        echo '<h1 style="color:#006400">Page file missing</h1>';
        echo '<p>Token: <code>' . e($token) . '</code></p>';
        echo '<p>Expected file: <code>pages/' . e($route['file']) . '</code></p>';
        echo '<p>This token is declared in <code>lib/routes.php</code> but the page file has not been created yet. See <code>.design/lfms/TASKS.md</code> for the task that delivers it.</p>';
        echo '</body></html>';
    } else {
        render_error(500, $routes);
    }
    exit;
}

require $page_file;
exit;


// =============================================================================
// Local helpers — only used inside index.php
// =============================================================================

/**
 * Return the default page token for a given role.
 * Used to land users on the right dashboard after login or a bare /index.php hit.
 */
function default_route_for_role(?string $role): string
{
    return match ($role) {
        'admin' => 'admin.dashboard',
        'staff' => 'staff.dashboard',
        'user'  => 'dashboard',
        default => 'login',
    };
}

/**
 * Render one of the registered error pages.
 * Falls back to a minimal inline body if even the error page is missing.
 */
function render_error(int $status, array $routes): void
{
    http_response_code($status);

    $token = (string) $status;
    if (isset($routes[$token])) {
        $file = __DIR__ . '/pages/' . $routes[$token]['file'];
        if (file_exists($file)) {
            require $file;
            exit;
        }
    }

    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html><body style=\"font-family:Segoe UI,sans-serif;padding:32px\"><h1>{$status}</h1></body></html>";
    exit;
}
