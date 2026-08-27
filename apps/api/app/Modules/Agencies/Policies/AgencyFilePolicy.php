<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Policies;

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Agencies\Enums\AgencyRole;
use App\Modules\Agencies\Models\AgencyFile;
use App\Modules\Agencies\Models\AgencyMembership;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Authorizes Agency Files operations (AH-091, chunk A) against the three
 * agency roles. The ratified matrix (plan-pause):
 *   - every active member (admin, manager, staff) → list / view / upload
 *   - admin + manager only → delete (staff 403)
 *
 * Every method receives an already route-bound model; cross-tenant access is
 * rejected by the controller's EXPLICIT `agency_id` check before this policy
 * ever runs (route-model binding resolves `{file}` before tenancy context
 * exists — the BrandPolicy/BrandLogoController precedent), so these checks
 * are purely role-based.
 */
final class AgencyFilePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->membership($user) !== null;
    }

    public function view(User $user, AgencyFile $file): bool
    {
        return $this->membership($user) !== null;
    }

    public function create(User $user): bool
    {
        return $this->membership($user) !== null;
    }

    public function delete(User $user, AgencyFile $file): bool
    {
        return $this->hasAnyRole($user, [AgencyRole::AgencyAdmin, AgencyRole::AgencyManager]);
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
}
