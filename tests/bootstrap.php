<?php
declare(strict_types=1);

define('JF_TESTING', true);
define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/vendor/autoload.php';

$GLOBALS['APP_CONFIG'] = [
    'app' => ['base_url' => '/journal-form'],
    'upload' => ['max_size_mb' => 10],
];
