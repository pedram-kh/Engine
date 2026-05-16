<?php

declare(strict_types=1);

use App\Core\Errors\ValidationExceptionRenderer;
use App\Core\Tenancy\EnsureTenancyContext;
use App\Core\Tenancy\SetTenancyContext;
use App\Core\Tenancy\SetTenancyFromAgencyRoute;
use App\Modules\Audit\Http\Middleware\RequireActionReason;
use App\Modules\Identity\Http\Middleware\UseAdminSessionCookie;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        apiPrefix: 'api',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum SPA cookie auth. EnsureFrontendRequestsAreStateful is
        // prepended into the api group; on requests from any
        // SANCTUM_STATEFUL_DOMAINS origin it dynamically applies session +
        // CSRF middleware so the api routes can issue/consume session cookies.
        $middleware->statefulApi();

        // Two-SPA cookie isolation. Runs as a global middleware so it lands
        // BEFORE Sanctum's stateful injection (and therefore before
        // StartSession reads config('session.cookie')). The middleware is
        // path-aware and only rewrites the cookie name on `api/v1/admin/*`
        // requests — see UseAdminSessionCookie + docs/runbooks/local-dev.md.
        $middleware->prepend(UseAdminSessionCookie::class);

        // Tenant-scoped HTTP routes declare ->middleware('tenancy.set') to
        // populate the context from the authenticated user's primary
        // AgencyMembership (Sprint 2+ routes), then ->middleware('tenancy')
        // fails closed if the populator yielded nothing. The chunk-3 routes
        // use neither alias yet — auth endpoints are not tenant-scoped —
        // but the aliases are registered here so Sprint 2 routes pick them
        // up without ceremony. See docs/security/tenancy.md.
        $middleware->alias([
            'tenancy' => EnsureTenancyContext::class,
            'tenancy.set' => SetTenancyContext::class,
            // Sprint 2 agency-scoped routes: reads {agency} route binding,
            // verifies user membership, sets context, returns 404 on mismatch.
            // See docs/security/tenancy.md §3 and SetTenancyFromAgencyRoute.
            'tenancy.agency' => SetTenancyFromAgencyRoute::class,
            'action.reason' => RequireActionReason::class,
            'admin.session' => UseAdminSessionCookie::class,
        ]);

        // Module-specific middleware is registered by each module's ServiceProvider.
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Normalize FormRequest validation failures to the canonical JSON:API
        // error envelope (docs/04-API-DESIGN.md §8). Without this, Laravel's
        // default validation renderer returns `{message, errors:{field:[]}}`,
        // which the SPA's `ApiError.fromEnvelope` parser rejects as malformed
        // — surfacing every 422 as `[http.invalid_response_body]` in the UI.
        // See `App\Core\Errors\ValidationExceptionRenderer` for the contract.
        //
        // Scope-guarded to JSON requests so we don't disturb any future
        // server-rendered web form (the api/ apps emit JSON only today,
        // but the gate is cheap and forward-compatible).
        //
        // Module-specific exception rendering is registered by each module's ServiceProvider.
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->expectsJson()) {
                return ValidationExceptionRenderer::render($e, $request);
            }

            return null;
        });
    })->create();
