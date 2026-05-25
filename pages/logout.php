<?php
declare(strict_types=1);

/**
 * Logout — destroy the current session and return the user to the login page.
 *
 * Public route: calling logout while anonymous is a no-op + redirect, so
 * there is no auth gate here. The audit-log entry inside logout_user() runs
 * only if a user is actually logged in.
 */

logout_user();
go(url('/index.php?p=login'), ['info' => 'You have been logged out.']);
