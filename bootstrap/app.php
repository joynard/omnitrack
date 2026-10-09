<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Env;

/*
| The image ships without a .env on purpose: configuration must come from real
| environment variables (docker-compose env_file, Koyeb secrets). PHP's default
| variables_order omits "E", so $_ENV would be empty and every env() call would
| silently fall back to its default. Enabling putenv makes Env read the process
| environment as well.
*/
Env::enablePutenv();

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        // `health` is a URI, not a route file. routes/web.php re-registers
        // /up with a database-aware probe that takes precedence over this one.
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'harness.token' => \App\Http\Middleware\VerifyHarnessToken::class,
        ]);

        // JSON endpoints are called by fetch() from the Blade UI and by agents.
        // They authenticate via the session cookie / bearer token rather than a
        // rendered <form>, so CSRF token verification does not apply. The UI
        // still sends X-CSRF-TOKEN on every request.
        $middleware->validateCsrfTokens(except: [
            'api/harness/*',
            'ai/*',
            'board/*',
            'sync/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
