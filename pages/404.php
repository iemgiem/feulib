<?php
declare(strict_types=1);

$status       = '404';
$label        = 'Not Found';
$heading      = 'We could not find that page.';
$body         = 'The page you were looking for does not exist. It may have been moved, or the link you followed may be out of date.';
$action_label = 'Return to dashboard';
$action_url   = url('/index.php');

require __DIR__ . '/../partials/error_page.php';
