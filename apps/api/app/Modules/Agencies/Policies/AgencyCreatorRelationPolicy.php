<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Policies;

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Agencies\Enums\AgencyRole;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Agencies\Models\AgencyMembership;
use App\Modules\Brands\Policies\BrandPolicy;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Authorises reads of an agency's creator roster (Sprint 4 Chunk 5) and the
 * per-creator detail surface (Sprint 6 Chunk 2a).
 *
 * READ (`viewAny`) mirrors {@see BrandPolicy::viewAny} — any agency member
 * (admin / manager / staff) may view the roster list AND the per-creator
 * detail view (D-2a-4: read stays any-member).
 *
 * WRITE (`update`) mirrors {@see BrandPolicy::update}'s role matrix — only
 * admin + manager may edit the relation's rating / notes; staff is view-only
 * (D-2a-4). This is the first write surface on this relation; the blacklist
 * apparatus + counters + relationship_status remain out of scope here (Sprint
 * 7 / Sprint 8 / set elsewhere).
 *
 * The routes already sit behind the `tenancy.agency` middleware, which returns
 * a 404 invisibility response for non-members before any gate is reached. The
 * gate checks are therefore belt-and-suspenders that also document the
 * membership / role intent (matching the house pattern established by
 * BrandController).
 */
final class AgencyCreatorRelationPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->membership($user) !== null;
    }

    /**
     * Edit the relation's rating + notes (D-2a-3 / D-2a-4). Admin + manager
     * write; staff view-only (403). Mirrors {@see BrandPolicy::update}.
     */
    public function update(User $user, AgencyCreatorRelation $relation): bool
    {
        return $this->hasAnyRole($user, [AgencyRole::AgencyAdmin, AgencyRole::AgencyManager]);
    }

    /** @param list<AgencyRole> $roles */
    private function hasAnyRole(User $user, array $roles): bool
    {
        $membership = $this->membership($user);

        return $membership !== null && in_array($membership->role, $roles, true);
    }

    private function membership(User $user): ?AgencyMembership
    {
        return AgencyMembership::withoutGlobalScope(BelongsToAgencyScope::class)
            ->where('user_id', $user->id)
            ->whereNotNull('accepted_at')
            ->whereNull('deleted_at')
            ->first();
    }
}
