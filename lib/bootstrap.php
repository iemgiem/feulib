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
require_once __DIR__ . '/redirect.php';   // depends on flash
require_once __DIR__ . '/view.php';       // url(), asset(), auth_card_open/close
require_once __DIR__ . '/../partials/layout.php'; // layout_open/close, breadcrumb, page_header, sidebar_items

// ---------------------------------------------------------------------------
// 4. Start the session (must happen before any output)
// ---------------------------------------------------------------------------

session_boot();

// ---------------------------------------------------------------------------
// 5. Uncaught-exception handler — last line of defense
// ---------------------------------------------------------------------------

set_exception_handler(function (\Throwable $e): void {
    if (cfg('app.env') === 'development') {
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
