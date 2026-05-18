<?php
declare(strict_types=1);

/**
 * Redirect helpers — issue a Location header and exit.
 *
 *   go('/index.php?p=dashboard');
 *   go('/index.php?p=lost.created&id=' . $id, ['success' => 'Report submitted.']);
 *   back(['errors' => $errors, 'old' => $_POST]);
 *
 * Both helpers always call exit() — caller never returns. The optional
 * $flash argument writes one or more keys to the flash bag in the same call.
 */

/**
 * Validate that a URL points back at our own application — used to defuse
 * open-redirect attacks via ?next= parameters.
 *
 * Returns true only when:
 *   - URL starts with a single '/' (not '//' which is protocol-relative).
 *   - URL contains no CR/LF (which would smuggle additional headers).
 *
 * Callers should fall back to a safe default URL on false.
 */
function is_safe_local_url(string $candidate): bool
{
    if ($candidate === '' || $candidate[0] !== '/') {
        return false;
    }
    if (isset($candidate[1]) && $candidate[1] === '/') {
        return false; // protocol-relative ("//evil.com/...")
    }
    if (preg_match('/[\r\n]/', $candidate)) {
        return false; // header injection
    }
    return true;
}

/**
 * Redirect to an absolute path, optionally setting flash values first.
 */
function go(string $url, array $flash = []): void
{
    foreach ($flash as $key => $value) {
        flash_set($key, $value);
    }
    if (!headers_sent()) {
        header('Location: ' . $url, true, 302);
    }
    exit;
}

/**
 * Redirect back to the page that submitted the current request.
 * Falls back to the dashboard if the Referer header is absent.
 */
function back(array $flash = []): void
{
    $url = $_SERVER['HTTP_REFERER'] ?? '/index.php?p=dashboard';
    go($url, $flash);
}
