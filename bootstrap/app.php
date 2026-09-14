<?php

use App\Modules\Core\Http\Middleware\ContentSecurityPolicy;
use App\Modules\Core\Http\Middleware\EnsureRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Every approval screen in the legacy app checked only that *someone*
        // was logged in, which let a student open the Dean's page and approve
        // their own application. `role:` closes that hole declaratively.
        $middleware->alias([
            'role' => EnsureRole::class,
        ]);

        // A Content-Security-Policy on every HTML response. Blade's escaping
        // is the first defence against injected script; this is the one that
        // holds if escaping is ever bypassed. See the middleware for why it
        // needs neither 'unsafe-eval' nor 'unsafe-inline' for scripts.
        $middleware->web(append: [
            ContentSecurityPolicy::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
