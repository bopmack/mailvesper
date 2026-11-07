<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Resolve the real release directory at runtime. With a symlinked `current` release, __DIR__ is fixed
// when OPcache compiles this file, so it can keep pointing at an old release after a deploy.
$script = isset($_SERVER['SCRIPT_FILENAME']) ? realpath($_SERVER['SCRIPT_FILENAME']) : false;
$publicDir = ($script && basename($script) === 'index.php') ? dirname($script) : __DIR__;

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = $publicDir.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require $publicDir.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once $publicDir.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
