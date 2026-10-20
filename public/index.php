<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$backend = null;
foreach ([dirname(__DIR__), dirname(__DIR__, 3), dirname(__DIR__, 4)] as $root) {
    $candidate = $root.'/leadspace_app/backend';
    if (is_dir($candidate)) {
        $backend = $candidate;
        break;
    }
}

if (! $backend) {
    http_response_code(500);
    exit('Leadspace backend is not available.');
}

if (file_exists($maintenance = $backend.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $backend.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once $backend.'/bootstrap/app.php';
$app->handleRequest(Request::capture());
