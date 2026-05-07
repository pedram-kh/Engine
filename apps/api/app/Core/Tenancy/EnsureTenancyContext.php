<?php

declare(strict_types=1);

namespace App\Core\Tenancy;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fail-closed assertion that a tenant-scoped HTTP route is only ever
 * reached with an active TenancyContext.
 *
 * Why this exists:
 *   `BelongsToAgencyScope` is a no-op when no context is set
 *   (App\Core\Tenancy\BelongsToAgencyScope::apply). That design lets
 *   admin tooling and CLI commands query across tenants without sprinkling
 *   `withoutGlobalScope()` everywhere — but it has a sharp edge: forgetting
 *   to set the context on a tenant-scoped route would silently return
 *   cross-tenant data.
 *
 * This middleware closes that edge for HTTP routes. Mount it on every
 * route group that addresses agency-scoped resources, and the request
 * either has a TenancyContext or it 500s with a clear, named exception.
 *
 * Registered as the `tenancy` middleware alias by AppServiceProvider.
 *
 * Usage:
 *   Route::middleware(['auth:web', SetTenancyContext::class, 'tenancy'])
 *       ->group(function () { ... });
 *
 * The `SetTenancyContext` populator that runs before this guard is added
 * in Sprint 1 chunk 3. See docs/security/tenancy.md for the full contract.
 */
final class EnsureTenancyContext
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app(TenancyContext::class)->hasAgency()) {
            throw MissingTenancyContextException::onRoute($request->path());
        }

        return $next($request);
    }
}
