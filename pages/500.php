<?php
declare(strict_types=1);

$status       = '500';
$label        = 'Server Error';
$heading      = 'The system encountered an error.';
$body         = 'We are sorry — something went wrong on our side. Please try again in a moment. If the problem continues, contact the library administrator.';
$action_label = 'Return to dashboard';
$action_url   = url('/index.php');

require __DIR__ . '/../partials/error_page.php';
