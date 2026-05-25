<?php
declare(strict_types=1);

/**
 * Sanitize helpers — output escaping + light input cleaning.
 *
 *   e($value)         Escape for HTML output. Use in every <?= ?> that prints user data.
 *   clean($string)    Strip tags + trim whitespace. Use on free-text input before storing.
 *   clean_id($string) Allow only [A-Za-z0-9_-] — for student/employee numbers, ref codes.
 */

/**
 * HTML-escape a value for safe rendering inside element content or attributes.
 * Always pass user-controlled data through this on the way OUT.
 */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Light input cleanup for free-text fields (descriptions, notes).
 * Removes HTML tags and surrounding whitespace; preserves inner spaces.
 */
function clean(?string $value): string
{
    return trim(strip_tags($value ?? ''));
}

/**
 * Whitelist filter for identifier-style strings (student numbers, ref codes).
 * Keeps letters, digits, hyphens, and underscores; strips everything else.
 */
function clean_id(?string $value): string
{
    return preg_replace('/[^A-Za-z0-9\-_]/', '', $value ?? '') ?? '';
}
