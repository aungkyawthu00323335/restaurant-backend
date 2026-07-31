<?php

use App\Http\Middleware\EnsurePosApiToken;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'pos.api' => EnsurePosApiToken::class,
            'user.auth' => \App\Http\Middleware\AuthenticateUserToken::class,
            'report.log' => \App\Http\Middleware\LogReportViews::class,
            'outlet.context' => \App\Http\Middleware\SetOutletContext::class,
            'outlet.access' => \App\Http\Middleware\EnsureOutletAccess::class,
            'idempotent' => \App\Http\Middleware\EnsureIdempotentRequest::class,
            'permission' => \App\Http\Middleware\RequirePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
