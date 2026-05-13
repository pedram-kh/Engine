<?php

declare(strict_types=1);

namespace App\Core\Tenancy;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyMembership;
use App\Modules\Identity\Models\User;
use App\Providers\AppServiceProvider;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Populates {@see TenancyContext} from the `{agency}` route-model binding
 * AND verifies the authenticated user has an active membership in that agency.
 *
 * Added in Sprint 2 Chunk 1 — the first sprint that ships
 * `/api/v1/agencies/{agency}/*` routes. See the note in
 * `Providers/AppServiceProvider.php` and `docs/security/tenancy.md §3`.
 *
 * Behaviour matrix:
 *   - Unauthenticated request: no-op (auth middleware must run first;
 *     if not, EnsureTenancyContext will 500 fail-closed downstream).
 *   - Authenticated user with membership in the route's agency: sets
 *     TenancyContext to the resolved agency's id. Request proceeds.
 *   - Authenticated user WITHOUT membership in the route's agency:
 *     returns 404. Returning 403 would confirm the agency ULID is valid
 *     (existence leak); 404 is the correct non-fingerprinting response.
 *     Aligns with docs/05-SECURITY-COMPLIANCE.md §7 and the cross-tenant
 *     test contract in docs/07-TESTING.md §3.1.
 *
 * Usage in route groups:
 *   Route::middleware(['auth:web', 'tenancy.agency', 'tenancy'])
 *       ->prefix('agencies/{agency}')
 *       ->group(function (): void { ... });
 *
 * Pair with the `tenancy` alias (EnsureTenancyContext) as the fail-closed
 * safety net: if this populator somehow yields no context, the downstream
 * guard 500s clearly instead of silently returning cross-tenant data.
 *
 * Registered as the `tenancy.agency` middleware alias by
 * {@see AppServiceProvider}.
 */
final class SetTenancyFromAgencyRoute
{
    public function __construct(private readonly TenancyContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $agency = $request->route('agency');

        if (! $agency instanceof Agency) {
            // Route-model binding hasn't resolved yet (shouldn't happen
            // when this middleware is mounted after the route group's
            // implicit binding, but guard defensively).
            return $next($request);
        }

        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $hasMembership = AgencyMembership::withoutGlobalScope(BelongsToAgencyScope::class)
            ->where('agency_id', $agency->id)
            ->where('user_id', $user->id)
            ->whereNotNull('accepted_at')
            ->whereNull('deleted_at')
            ->exists();

        if (! $hasMembership) {
            // 404 — do not leak whether the agency ULID is valid.
            abort(404);
        }

        $this->context->setAgencyId($agency->id);

        return $next($request);
    }
}
