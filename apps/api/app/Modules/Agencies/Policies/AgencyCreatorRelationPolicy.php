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
 * Authorises reads of an agency's creator roster (Sprint 4 Chunk 5), the
 * per-creator detail surface (Sprint 6 Chunk 2a), and the global discovery
 * surface (Sprint 6.6a).
 *
 * READ (`viewAny`) mirrors {@see BrandPolicy::viewAny} — any agency member
 * (admin / manager / staff) may view the roster list AND the per-creator
 * detail view (D-2a-4: read stays any-member).
 *
 * DISCOVER (`discover`, Sprint 6.6a D-1) is a distinct any-member ability for
 * the global-pool browse + public profile — kept separate from `viewAny`
 * because that ability is conceptually "view relations," a stretch for "browse
 * the pool." Same authz floor, clearer intent.
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
     * Browse the GLOBAL creator pool + view a public profile (Sprint 6.6a,
     * D-1). A DISTINCT ability from `viewAny` on purpose: `viewAny` means "view
     * the agency's relations" (relation-scoped), whereas discovery queries the
     * global `creators` pool — semantically a different read, so a separate
     * ability documents the intent (the reviewer lean). Authz is the same
     * floor: any agency member may discover.
     */
    public function discover(User $user): bool
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

    /**
     * Send (or re-send) a discovery connection request to a creator
     * (Sprint 6.6b, D-7). Same admin/manager floor as {@see self::update} —
     * a stateful write that creates/transitions the relation, so staff is
     * 403. A class-level ability (no relation instance exists yet on a
     * net-new send), mirroring the role matrix of `update`.
     */
    public function sendRequest(User $user): bool
    {
        return $this->hasAnyRole($user, [AgencyRole::AgencyAdmin, AgencyRole::AgencyManager]);
    }

    /**
     * Blacklist (or un-blacklist) a creator — agency-wide OR brand-scoped
     * (Sprint 7, D-7). Same admin/manager floor as {@see self::update} (the
     * rating/notes precedent) and {@see self::sendRequest}; staff is view-only
     * (403). A CLASS-LEVEL ability: a brand-scoped blacklist has no relation
     * instance, and the role matrix is identical for both scopes, so the gate
     * keys off role alone (mirrors the sendRequest precedent).
     */
    public function blacklist(User $user): bool
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
