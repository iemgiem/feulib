<?php
declare(strict_types=1);

/**
 * Flash messages — one-shot session values that survive a single redirect.
 *
 * Typical use:
 *   flash_set('success', 'Lost item reported. Reference: ' . $ref);
 *   go('/index.php?p=dashboard');
 *
 * On the next page:
 *   $msg = flash_get('success');
 *   if ($msg) { echo '<div class="toast">'. e($msg) .'</div>'; }
 *
 * Internally a flash is just $_SESSION['_flash'][$key] that auto-clears on read.
 */

/**
 * Set a flash value to be read once on the next request.
 */
function flash_set(string $key, $value): void
{
    $_SESSION['_flash'][$key] = $value;
}

/**
 * Read AND clear a single flash value. Returns null if absent.
 */
function flash_get(string $key)
{
    $value = $_SESSION['_flash'][$key] ?? null;
    if (isset($_SESSION['_flash'][$key])) {
        unset($_SESSION['_flash'][$key]);
    }
    return $value;
}

/**
 * Peek at a flash value without clearing it. Useful when rendering twice.
 */
function flash_peek(string $key)
{
    return $_SESSION['_flash'][$key] ?? null;
}

/**
 * Drain every flash value into a fresh array and clear the bucket.
 */
function flash_all(): array
{
    $all = $_SESSION['_flash'] ?? [];
    $_SESSION['_flash'] = [];
    return $all;
}
