<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->prepend(\App\Http\Middleware\RejectPathTraversal::class);

        $middleware->appendToGroup('web', \App\Http\Middleware\LimitPayloadSize::class);
        $middleware->appendToGroup('web', \App\Http\Middleware\EnsureEmployeeStatusActive::class);
        $middleware->appendToGroup('web', \App\Http\Middleware\ForcePasswordChange::class);

        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureRole::class,
            'deny.job.order' => \App\Http\Middleware\DenyJobOrder::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (PostTooLargeException $e, $request) {
            $max = ini_get('upload_max_filesize');

            return back()
                ->withInput()
                ->withErrors(['backup_file' => "The uploaded file exceeds the server limit of {$max}. Contact your system administrator to increase upload_max_filesize."]);
        });
    })->create();

/*
|--------------------------------------------------------------------------
| Shared-Hosting: override public path
|--------------------------------------------------------------------------
|
| When the root-level index.php defines PUBLIC_PATH, the public/ contents
| live in the project root.  Tell Laravel so that public_path(), asset(),
| Vite, and mix all resolve correctly.
|
*/
if (defined('PUBLIC_PATH')) {
    $app->usePublicPath(PUBLIC_PATH);
}

return $app;
