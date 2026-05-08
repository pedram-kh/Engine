<?php

declare(strict_types=1);

namespace App\Core\Tenancy;

use App\Modules\Agencies\Models\AgencyMembership;
use App\Modules\Identity\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Populates {@see TenancyContext} from the authenticated user's primary
 * AgencyMembership.
 *
 * Phase 1 every agency_user has at most one membership (single-agency
 * model — see docs/00-MASTER-ARCHITECTURE.md §4 and
 * docs/03-DATA-MODEL.md §3). When that invariant is relaxed in Phase 2
 * (multi-agency users), this middleware will read the active workspace
 * from a session attribute instead. The contract for callers stays the
 * same: after this middleware runs, TenancyContext is set whenever the
 * user has any agency membership.
 *
 * Behavior matrix:
 *   - Unauthenticated request: no-op (auth middleware should be ahead of
 *     this in the route group; if it isn't, this still fails open and the
 *     downstream EnsureTenancyContext guard will 500 the request fail-closed).
 *   - Authenticated agency_user / brand_user: looks up the first non-soft-
 *     deleted AgencyMembership and sets the context to that agency's id.
 *   - Authenticated creator (no memberships): no-op. Creator routes do not
 *     mount the EnsureTenancyContext guard, so this is safe.
 *   - Authenticated platform_admin: no-op. Admin routes are explicitly
 *     cross-tenant and never mount the EnsureTenancyContext guard. Admin
 *     impersonation (Sprint 13) will set context manually inside the
 *     impersonation flow.
 *
 * Pair with `EnsureTenancyContext` on the route group: this middleware
 * populates, that one fails closed if the population yielded nothing on
 * a route that needed it.
 *
 * Registered as the `tenancy.set` middleware alias by AppServiceProvider.
 *
 * Usage:
 *   Route::middleware(['auth:web', 'tenancy.set', 'tenancy'])
 *       ->prefix('agencies/{agency}')
 *       ->group(function () { ... });
 */
final class SetTenancyContext
{
    public function __construct(private readonly TenancyContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            $membership = AgencyMembership::query()
                ->where('user_id', $user->getKey())
                ->orderBy('id')
                ->first();

            if ($membership instanceof AgencyMembership) {
                $this->context->setAgencyId((int) $membership->agency_id);
            }
        }

        return $next($request);
    }
}
