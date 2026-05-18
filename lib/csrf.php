<?php
declare(strict_types=1);

/**
 * CSRF protection — synchronizer-token pattern.
 *
 * Render in every <form method="POST">:
 *   <?= csrf_field() ?>
 *
 * Validate at the top of every POST handler:
 *   csrf_check();
 *
 * The token lives in $_SESSION['csrf_token'] and is reused for the life of
 * the session. We do NOT rotate per-request — that breaks back-button + tabs
 * for the kind of staff workflow LFMS supports.
 */

const CSRF_FIELD_NAME = 'csrf_token';

/**
 * Return (and lazily generate) the per-session CSRF token.
 */
function csrf_token(): string
{
    if (empty($_SESSION[CSRF_FIELD_NAME])) {
        $_SESSION[CSRF_FIELD_NAME] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_FIELD_NAME];
}

/**
 * Return an HTML hidden input carrying the CSRF token.
 * Drop into every form.
 */
function csrf_field(): string
{
    return '<input type="hidden" name="' . CSRF_FIELD_NAME . '" value="' . e(csrf_token()) . '">';
}

/**
 * Validate the CSRF token from a POST submission.
 * On mismatch: 419 status, hard exit. Caller never returns.
 */
function csrf_check(): void
{
    $submitted = $_POST[CSRF_FIELD_NAME] ?? '';
    if (!is_string($submitted) || !hash_equals(csrf_token(), $submitted)) {
        http_response_code(419);
        header('Content-Type: text/html; charset=utf-8');
        echo '<h1>Session expired</h1><p>The form you submitted is no longer valid. Please go back, refresh the page, and try again.</p>';
        exit;
    }
}
