<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$backend = dirname(__DIR__).'/leadspace_app/backend';

if (file_exists($maintenance = $backend.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $backend.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once $backend.'/bootstrap/app.php';
$app->handleRequest(Request::capture());
