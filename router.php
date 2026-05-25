<?php
/**
 * Router script for PHP's built-in server (used by Railway deployment).
 *
 * PHP's built-in server needs this to distinguish between:
 *   - Static asset requests  (CSS, JS, images) → serve the file directly
 *   - Everything else        → hand off to index.php (the front controller)
 *
 * This file is ONLY used when the app runs under `php -S` (Railway).
 * Apache on XAMPP ignores it entirely.
 */

$uri  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $uri;

// If the URI points to a real file (CSS, JS, image, etc.) let the built-in
// server serve it directly by returning false.
if ($uri !== '/' && file_exists($file) && !is_dir($file)) {
    return false;
}

// Everything else goes through the front controller.
require __DIR__ . '/index.php';
