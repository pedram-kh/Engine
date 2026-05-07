<?php

declare(strict_types=1);

use App\Core\Tenancy\EnsureTenancyContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        apiPrefix: 'api',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Tenant-scoped HTTP routes must declare ->middleware('tenancy').
        // The guard 500s with MissingTenancyContextException if the populator
        // (SetTenancyContext, added in chunk 3) failed to set the context —
        // see docs/security/tenancy.md for the full contract.
        $middleware->alias([
            'tenancy' => EnsureTenancyContext::class,
        ]);

        // Module-specific middleware is registered by each module's ServiceProvider.
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Module-specific exception rendering is registered by each module's ServiceProvider.
    })->create();
