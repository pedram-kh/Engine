<?php

declare(strict_types=1);

namespace App\Modules\Creators\Policies;

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Agencies\Models\AgencyMembership;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Authorises operations on the global Creator entity.
 *
 * Per kickoff §1.3 and docs/security/tenancy.md:
 *   - view:           the owning user, OR an agency member of an agency
 *                     with a non-blacklisted agency_creator_relations row
 *                     pointing at this creator, OR a platform_admin.
 *   - update:         the owning user only (wizard surface). Admin
 *                     per-field edits land in Chunk 3 via a separate
 *                     `adminUpdate` method on the admin-route table.
 *   - approve/reject: deferred to Sprint 4 explicitly (admin actions).
 *
 * Defense-in-depth (#40): every method ships with independent unit-test
 * coverage. Break-revert: temporarily flip a method to true/false,
 * confirm a test fails, revert.
 */
final class CreatorPolicy
{
    use HandlesAuthorization;

    /**
     * Listing creators is restricted to platform admins; the agency-side
     * roster view uses a separate AgencyCreatorRelation listing endpoint.
     */
    public function viewAny(User $user): bool
    {
        return $user->type === UserType::PlatformAdmin;
    }

    public function view(User $user, Creator $creator): bool
    {
        if ($user->type === UserType::PlatformAdmin) {
            return true;
        }

        if ($this->isOwner($user, $creator)) {
            return true;
        }

        return $this->hasAgencyAccess($user, $creator);
    }

    /**
     * Creator self-edit (wizard write path). Admin per-field edits use
     * the separate `adminUpdate` method (Chunk 3 admin SPA).
     */
    public function update(User $user, Creator $creator): bool
    {
        return $this->isOwner($user, $creator);
    }

    /**
     * Admin per-field edit on the admin-route surface. Sprint 3 Chunk 3
     * (admin creator-detail page) wires this through the admin-route
     * controller; Chunk 1 ships the policy method so the contract is
     * pinned and the admin wiring is a thin attach.
     */
    public function adminUpdate(User $user, Creator $creator): bool
    {
        return $user->type === UserType::PlatformAdmin;
    }

    /**
     * Sprint 4 admin action — kept here as a stub returning false so the
     * authorize() contract is in place for the admin SPA's approve UI
     * before Sprint 4 implements the workflow.
     */
    public function approve(User $user, Creator $creator): bool
    {
        return false;
    }

    /**
     * Sprint 4 admin action — same rationale as approve().
     */
    public function reject(User $user, Creator $creator): bool
    {
        return false;
    }

    // -------------------------------------------------------------------------

    private function isOwner(User $user, Creator $creator): bool
    {
        return $creator->user_id === $user->id;
    }

    /**
     * True when the user is an active member of an agency that has a
     * non-blacklisted agency_creator_relations row pointing at this
     * creator.
     */
    private function hasAgencyAccess(User $user, Creator $creator): bool
    {
        if ($user->type !== UserType::AgencyUser) {
            return false;
        }

        $agencyIds = $this->activeAgencyIds($user);
        if ($agencyIds === []) {
            return false;
        }

        return AgencyCreatorRelation::query()
            ->withoutGlobalScope(BelongsToAgencyScope::class)
            ->where('creator_id', $creator->id)
            ->whereIn('agency_id', $agencyIds)
            ->where(function ($query): void {
                $query->where('is_blacklisted', false)
                    ->orWhereNull('is_blacklisted');
            })
            ->exists();
    }

    /**
     * @return list<int>
     */
    private function activeAgencyIds(User $user): array
    {
        $ids = AgencyMembership::withoutGlobalScope(BelongsToAgencyScope::class)
            ->where('user_id', $user->id)
            ->whereNotNull('accepted_at')
            ->whereNull('deleted_at')
            ->pluck('agency_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return array_values($ids);
    }
}
