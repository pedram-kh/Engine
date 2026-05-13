<?php

declare(strict_types=1);

namespace App\Modules\Brands\Policies;

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Agencies\Enums\AgencyRole;
use App\Modules\Agencies\Models\AgencyMembership;
use App\Modules\Brands\Models\Brand;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Authorises brand operations against the three agency roles.
 *
 * Per docs/20-PHASE-1-SPEC.md §4.2 and the Sprint 2 kickoff permission matrix:
 *   - agency_admin   → full access (view, create, update, archive, restore)
 *   - agency_manager → create / update / archive / restore + view
 *   - agency_staff   → view only
 *
 * All policy methods receive the resolved Brand model, which already has
 * agency_id set and has been scope-checked by the tenancy middleware.
 * Policy checks are therefore purely role-based; cross-tenant access is
 * prevented at the middleware layer before reaching here.
 */
final class BrandPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->membership($user) !== null;
    }

    public function view(User $user, Brand $brand): bool
    {
        return $this->membership($user) !== null;
    }

    public function create(User $user): bool
    {
        return $this->hasAnyRole($user, [AgencyRole::AgencyAdmin, AgencyRole::AgencyManager]);
    }

    public function update(User $user, Brand $brand): bool
    {
        return $this->hasAnyRole($user, [AgencyRole::AgencyAdmin, AgencyRole::AgencyManager]);
    }

    /**
     * Archive = set status to `archived`. Staff cannot archive.
     */
    public function archive(User $user, Brand $brand): bool
    {
        return $this->hasAnyRole($user, [AgencyRole::AgencyAdmin, AgencyRole::AgencyManager]);
    }

    /**
     * Restore from archived state. Staff cannot restore.
     */
    public function restore(User $user, Brand $brand): bool
    {
        return $this->hasAnyRole($user, [AgencyRole::AgencyAdmin, AgencyRole::AgencyManager]);
    }

    /**
     * Permanent deletion (soft-delete, i.e. sets deleted_at).
     * Admin-only per Phase 1 spec.
     */
    public function delete(User $user, Brand $brand): bool
    {
        return $this->hasRole($user, AgencyRole::AgencyAdmin);
    }

    // -------------------------------------------------------------------------

    private function membership(User $user): ?AgencyMembership
    {
        return AgencyMembership::withoutGlobalScope(BelongsToAgencyScope::class)
            ->where('user_id', $user->id)
            ->whereNotNull('accepted_at')
            ->whereNull('deleted_at')
            ->first();
    }

    /** @param list<AgencyRole> $roles */
    private function hasAnyRole(User $user, array $roles): bool
    {
        $membership = $this->membership($user);

        return $membership !== null && in_array($membership->role, $roles, true);
    }

    private function hasRole(User $user, AgencyRole $role): bool
    {
        return $this->hasAnyRole($user, [$role]);
    }
}
