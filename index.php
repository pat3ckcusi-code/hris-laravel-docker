<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

/*
|--------------------------------------------------------------------------
| Shared-Hosting Public Path Override
|--------------------------------------------------------------------------
|
| When deploying to shared hosting the contents of the original public/
| directory are placed in the project root.  This constant tells
| bootstrap/app.php to override public_path() so that Vite, asset(),
| and all other helpers resolve files relative to THIS directory instead
| of the default  base_path('public').
|
*/
define('PUBLIC_PATH', __DIR__);

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/bootstrap/app.php';

$app->handleRequest(Request::capture());
