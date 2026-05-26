<?php
declare(strict_types=1);

/**
 * Bootstrap — loaded as the first require in /index.php.
 *
 * Wires the lib/ toolkit in dependency order, configures error handling
 * based on environment, sets the timezone, starts the session, and
 * installs an uncaught-exception handler.
 *
 * After this file runs, every helper function (cfg, db, q*, e, clean,
 * csrf_*, flash_*, validate, audit_log, *_user, has_role, require_*,
 * go, back) is available globally.
 */

// ---------------------------------------------------------------------------
// 1. Config loader — lazy, cached, accessible via cfg('section.key')
// ---------------------------------------------------------------------------

/**
 * Read a value from config.php using dot notation.
 *
 *   cfg()                  whole config array
 *   cfg('db')              entire 'db' section
 *   cfg('db.host')         single value
 *   cfg('session.cookie_samesite')
 *
 * Returns null when the path does not exist.
 */
function cfg(?string $path = null)
{
    static $config = null;
    if ($config === null) {
        $config_path = __DIR__ . '/../config.php';
        if (!file_exists($config_path)) {
            $config_path = __DIR__ . '/../config.example.php';
        }
        $config = require $config_path;
    }
    if ($path === null) {
        return $config;
    }
    $value = $config;
    foreach (explode('.', $path) as $key) {
        if (!is_array($value) || !array_key_exists($key, $value)) {
            return null;
        }
        $value = $value[$key];
    }
    return $value;
}

// ---------------------------------------------------------------------------
// 2. Timezone + error reporting (driven by config 'app.env')
// ---------------------------------------------------------------------------

date_default_timezone_set(cfg('app.timezone') ?? 'Asia/Manila');

if (cfg('app.env') === 'development') {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL & ~E_DEPRECATED);
}

// ---------------------------------------------------------------------------
// 3. Load the lib/ toolkit in dependency order
// ---------------------------------------------------------------------------

require_once __DIR__ . '/sanitize.php';   // e(), clean() — used by csrf, output
require_once __DIR__ . '/db.php';         // db(), q*()
require_once __DIR__ . '/flash.php';      // session-dependent; needs session
require_once __DIR__ . '/csrf.php';       // session-dependent
require_once __DIR__ . '/validate.php';   // pure functions
require_once __DIR__ . '/audit.php';      // depends on db + session
require_once __DIR__ . '/auth.php';       // depends on db + session + audit
require_once __DIR__ . '/upload.php';     // depends on db + audit + session
require_once __DIR__ . '/matching.php';   // depends on db + audit
require_once __DIR__ . '/its.php';        // depends on db + audit
require_once __DIR__ . '/export.php';     // pure functions, no deps
require_once __DIR__ . '/notify.php';     // depends on db + settings
require_once __DIR__ . '/redirect.php';   // depends on flash
require_once __DIR__ . '/view.php';       // url(), asset(), auth_card_open/close
require_once __DIR__ . '/../partials/layout.php'; // layout_open/close, breadcrumb, page_header, sidebar_items
require_once __DIR__ . '/../partials/modal.php';  // modal_open/close, modal_footer_open/close

// ---------------------------------------------------------------------------
// 4. Start the session (must happen before any output)
// ---------------------------------------------------------------------------

session_boot();

// ---------------------------------------------------------------------------
// 5. HTTP security headers — sent on every response in both environments.
//    These are PHP-level fallbacks; for production, mirror them in Apache
//    config or the root .htaccess as well (belt-and-suspenders).
// ---------------------------------------------------------------------------

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
// HSTS is only safe over HTTPS. Enable it in Apache/Nginx once the cert
// is installed — do NOT send it over plain HTTP or browsers will lock out
// users if the cert ever lapses.
// header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

// ---------------------------------------------------------------------------
// 6. Production safety guard — log loudly if launched with dev-default values
// ---------------------------------------------------------------------------

if (cfg('app.env') === 'production') {
    $__prod_warnings = [];
    if ((string)(cfg('db.pass') ?? '') === '') {
        $__prod_warnings[] = 'db.pass is empty — set a real database password in config.php.';
    }
    if (cfg('its.auth_value') === 'dev-token-change-me-before-production') {
        $__prod_warnings[] = 'its.auth_value is the dev placeholder — rotate it in config.php.';
    }
    if (cfg('session.cookie_secure') === false) {
        $__prod_warnings[] = 'session.cookie_secure is false — set to true in config.php once HTTPS is active.';
    }
    foreach ($__prod_warnings as $__msg) {
        error_log('[LFMS PRODUCTION WARNING] ' . $__msg);
    }
    unset($__prod_warnings, $__msg);
}

// ---------------------------------------------------------------------------
// 7. Uncaught-exception handler — last line of defense
// ---------------------------------------------------------------------------

set_exception_handler(function (\Throwable $e): void {
    // Temporary debug mode — remove after diagnosing Railway deploy issue
    $show_debug = cfg('app.env') === 'development' || isset($_GET['_debug_deploy']);

    if ($show_debug) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo '<h1>Uncaught ' . htmlspecialchars(get_class($e)) . '</h1>';
        echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
        echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
    } else {
        error_log($e->getMessage() . "\n" . $e->getTraceAsString());
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo '<h1>Server error</h1><p>The system encountered an error. Please try again or contact the library administrator.</p>';
    }
    exit;
});
