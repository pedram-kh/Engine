<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Policies;

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Agencies\Enums\AgencyRole;
use App\Modules\Agencies\Models\AgencyMembership;
use App\Modules\Brands\Policies\BrandPolicy;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Authorises campaign operations against the three agency roles (Sprint 8
 * Chunk 1, D-10). Mirrors {@see BrandPolicy}.
 *
 * Per docs/20-PHASE-1-SPEC.md §4.2 (the role matrix):
 *   - agency_admin   → full access (view, create, update)
 *   - agency_manager → "campaigns and creators" — create / update + view
 *   - agency_staff   → "execute campaigns; no creating" — view only
 *
 * Staff's "execute" (invite/manage assignments) lands in Chunk 2 as the
 * {@see invite()} ability — broader than `create`, because inviting creators
 * IS executing a campaign (spec §4.2: staff "execute campaigns"), as distinct
 * from CREATING one (admin/manager). Cross-tenant access is prevented at the
 * middleware layer.
 */
final class CampaignPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->membership($user) !== null;
    }

    public function view(User $user, Campaign $campaign): bool
    {
        return $this->membership($user) !== null;
    }

    public function create(User $user): bool
    {
        return $this->hasAnyRole($user, [AgencyRole::AgencyAdmin, AgencyRole::AgencyManager]);
    }

    public function update(User $user, Campaign $campaign): bool
    {
        return $this->hasAnyRole($user, [AgencyRole::AgencyAdmin, AgencyRole::AgencyManager]);
    }

    /**
     * The "execute" ability (Sprint 8 Chunk 2, D-6) — inviting / re-inviting
     * creators to a campaign. Broader than {@see create()}: admin + manager +
     * STAFF (staff "execute campaigns" per spec §4.2 — if staff couldn't
     * invite, "execute" would be hollow).
     */
    public function invite(User $user, Campaign $campaign): bool
    {
        return $this->hasAnyRole($user, [
            AgencyRole::AgencyAdmin,
            AgencyRole::AgencyManager,
            AgencyRole::AgencyStaff,
        ]);
    }

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
