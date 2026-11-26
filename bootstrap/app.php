<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PreventSearchIndexing;
use App\Http\Middleware\ProtectSharedBrowserSession;
use App\Http\Middleware\RestrictEmployeeDuringCashShift;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(PreventSearchIndexing::class);

        $middleware->trimStrings(except: ['employees.*.password', 'employees.*.password_confirmation']);

        $middleware->encryptCookies(except: ['sidebar_state']);

        $middleware->web(append: [
            ProtectSharedBrowserSession::class,
            RestrictEmployeeDuringCashShift::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['employees']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

if (PHP_OS_FAMILY === 'Windows' && preg_match('/^[A-Za-z]:/', $app->storagePath(), $storageDrive)) {
    $app->addAbsoluteCachePathPrefix($storageDrive[0]);
}

return $app;
