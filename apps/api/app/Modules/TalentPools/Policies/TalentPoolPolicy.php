<?php

declare(strict_types=1);

namespace App\Modules\TalentPools\Policies;

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Agencies\Enums\AgencyRole;
use App\Modules\Agencies\Models\AgencyMembership;
use App\Modules\Brands\Policies\BrandPolicy;
use App\Modules\Identity\Models\User;
use App\Modules\TalentPools\Models\TalentPool;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Authorises talent-pool operations against the three agency roles —
 * mirrors {@see BrandPolicy} verbatim
 * (Sprint 6 Chunk 2b, D-2b-6):
 *   - agency_admin   → full access (view, create, update, archive, restore,
 *                      delete, add/remove members)
 *   - agency_manager → create / update / archive / restore + view +
 *                      add/remove members
 *   - agency_staff   → view only
 *
 * Adding/removing a creator to/from a pool is a membership WRITE, so it is
 * gated by `update` (admin/manager) — staff is view-only (D-2b-8).
 *
 * The routes already sit behind `tenancy.agency`, which 404s a non-member
 * before any gate is reached. These checks are belt-and-suspenders that also
 * document the role intent (matching BrandController's house pattern).
 */
final class TalentPoolPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->membership($user) !== null;
    }

    public function view(User $user, TalentPool $pool): bool
    {
        return $this->membership($user) !== null;
    }

    public function create(User $user): bool
    {
        return $this->hasAnyRole($user, [AgencyRole::AgencyAdmin, AgencyRole::AgencyManager]);
    }

    public function update(User $user, TalentPool $pool): bool
    {
        return $this->hasAnyRole($user, [AgencyRole::AgencyAdmin, AgencyRole::AgencyManager]);
    }

    /**
     * Archive = soft-delete. Staff cannot archive.
     */
    public function archive(User $user, TalentPool $pool): bool
    {
        return $this->hasAnyRole($user, [AgencyRole::AgencyAdmin, AgencyRole::AgencyManager]);
    }

    /**
     * Restore from the archived (soft-deleted) state. Staff cannot restore.
     */
    public function restore(User $user, TalentPool $pool): bool
    {
        return $this->hasAnyRole($user, [AgencyRole::AgencyAdmin, AgencyRole::AgencyManager]);
    }

    /**
     * Permanent deletion. Admin-only per the Phase 1 spec (mirrors brands).
     */
    public function delete(User $user, TalentPool $pool): bool
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
