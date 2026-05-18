<?php
declare(strict_types=1);

$status       = '403';
$label        = 'Forbidden';
$heading      = "You don't have access to this page.";
$body         = 'Your account is not authorized to view this section. If you believe this is a mistake, contact the library administrator.';
$action_label = is_authenticated() ? 'Return to your dashboard' : 'Sign in';
$action_url   = is_authenticated() ? url('/index.php') : url('/index.php?p=login');

require __DIR__ . '/../partials/error_page.php';
