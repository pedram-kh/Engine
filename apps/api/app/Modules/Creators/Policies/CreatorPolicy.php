<?php

declare(strict_types=1);

namespace App\Modules\Creators\Policies;

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Agencies\Models\AgencyMembership;
use App\Modules\Creators\Enums\ApplicationStatus;
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
     * Sprint 3 Chunk 4 admin action — flips application_status to
     * `approved` via the dedicated approve endpoint per Decision E2=b.
     * Gated on platform_admin user_type; agency users never approve.
     */
    public function approve(User $user, Creator $creator): bool
    {
        return $user->type === UserType::PlatformAdmin;
    }

    /**
     * Sprint 3 Chunk 4 admin action — same rationale as approve().
     */
    public function reject(User $user, Creator $creator): bool
    {
        return $user->type === UserType::PlatformAdmin;
    }

    /**
     * AH-005 — may the caller see this creator's optional CONTACT details
     * (phone / WhatsApp / mailing address) on the agency roster-detail surface?
     *
     * AH-051 (D-1) — the contact gate TIGHTENS to roster-only. It previously
     * accepted any non-blacklisted relation (which let a `pending_request`,
     * `declined`, or `prospect` agency see contact); it now requires THIS
     * agency to hold a `roster` (connected) relation to the creator that is
     * non-blacklisted. This aligns the code with the shipped consent promise
     * ("shared only with agencies you are connected to") and with AH-010: the
     * roster+non-blacklisted core is now sourced from the ONE shared
     * {@see AgencyCreatorRelation::scopePermitsMessaging()} primitive so contact
     * and messaging cannot drift on what "connected" means. Contact does NOT
     * add messaging's `approved` leg — a rostered relation is the consent
     * event; the creator's application-approval state is orthogonal here.
     *
     * AGENCY-SCOPED, deliberately NOT a user-wide union: the caller must be an
     * active member of THIS {@param $agency} AND that agency's OWN relation must
     * qualify. A multi-agency user viewing Agency A's page sees no contact if
     * Agency A is not rostered/blacklisted — even if another agency they belong
     * to has a clean roster relation.
     *
     * Platform admins always pass (admin view-only, AH-005 D6).
     */
    public function canSeeContactDetails(User $user, Creator $creator, Agency $agency): bool
    {
        if ($user->type === UserType::PlatformAdmin) {
            return true;
        }

        if ($user->type !== UserType::AgencyUser) {
            return false;
        }

        // Caller must actively belong to THIS agency (not merely any agency).
        if (! in_array($agency->id, $this->activeAgencyIds($user), true)) {
            return false;
        }

        // …and THIS agency must hold a non-blacklisted `roster` relation — the
        // shared roster+non-blacklisted primitive (AH-051 D-1). pending_request
        // / declined / prospect / ended / external all fail here.
        return AgencyCreatorRelation::query()
            ->withoutGlobalScope(BelongsToAgencyScope::class)
            ->where('creator_id', $creator->id)
            ->where('agency_id', $agency->id)
            ->permitsMessaging()
            ->exists();
    }

    /**
     * Sprint 4 Chunk 3 admin action (D-c3-3) — manually clears identity
     * verification (kyc_status → verified, kyc_method = manual). A
     * permanent compliance-sensitive override; platform_admin only, same
     * gate as approve / reject. No new sub-role (D-c3-8).
     */
    public function verifyIdentity(User $user, Creator $creator): bool
    {
        return $user->type === UserType::PlatformAdmin;
    }

    /**
     * AH-010 (D2) — may the caller participate in the 1:1 relationship message
     * thread between this {@param $agency} and this {@param $creator}?
     *
     * The LOAD-BEARING security gate (spam vector). Deliberately status-aware
     * and STRICTER than {@see self::canSeeContactDetails()} /
     * {@see self::hasNonBlacklistedRelation()} — those answer only
     * "not blacklisted," which would let a `declined` (or `prospect` /
     * `pending_request`) agency open a DM. Relationship messaging requires a
     * genuine, accepted, two-way working relationship, so {@see self::relationPermitsMessaging()}
     * demands an APPROVED creator AND a `roster` (only) relation that is
     * non-blacklisted — `external` is excluded (it is currently unreachable and
     * is semantically a non-roster, campaign-only engagement, AH-010 Step-0).
     *
     * On top of that relation predicate (which is symmetric — it is the same
     * for both parties), the AGENCY side additionally requires the caller to be
     * an active member of THIS agency; the CREATOR side requires owning the
     * creator profile. Platform admins are NOT party to a 1:1 relationship
     * thread and never pass (messaging is participation, not view-only).
     *
     * Break-revert (the spine's load-bearing test): loosen
     * {@see self::relationPermitsMessaging()} to the not-blacklisted-only
     * predicate → the declined-agency-blocked spec MUST fail → revert.
     */
    public function canMessageRelationship(User $user, Creator $creator, Agency $agency): bool
    {
        if (! $this->relationPermitsMessaging($creator, $agency)) {
            return false;
        }

        // Creator side — the owning user of the creator profile.
        if ($this->isOwner($user, $creator)) {
            return true;
        }

        // Agency side — an ACTIVE member of THIS agency (org-level: any active
        // member of the connected agency may participate, AH-010 Q4). A
        // multi-agency user only passes for the agency that actually holds the
        // qualifying relation.
        return $user->type === UserType::AgencyUser
            && in_array($agency->id, $this->activeAgencyIds($user), true);
    }

    /**
     * AH-010 (D2) — the status-aware relation predicate behind
     * {@see self::canMessageRelationship()}. Distinct from
     * {@see self::hasNonBlacklistedRelation()} ON PURPOSE: this is the
     * "these two have a real, active, accepted relationship" question, not the
     * looser "not blacklisted" one.
     *
     * ALL must hold:
     *   1. the creator's application is APPROVED (excludes incomplete / pending
     *      / rejected);
     *   2. THIS agency holds a `roster` relation to the creator (excludes
     *      prospect / pending_request / declined / external);
     *   3. that relation is non-blacklisted.
     */
    private function relationPermitsMessaging(Creator $creator, Agency $agency): bool
    {
        if ($creator->application_status !== ApplicationStatus::Approved) {
            return false;
        }

        // AH-012 (D3): the relation leg is now sourced from the shared
        // `permitsMessaging` scope so this single-pair gate and the set-valued
        // MessageableContactsFinder cannot drift. Semantics are unchanged — the
        // CreatorPolicyTest messaging suite stays green-unchanged (the
        // preservation proof).
        return AgencyCreatorRelation::query()
            ->withoutGlobalScope(BelongsToAgencyScope::class)
            ->where('creator_id', $creator->id)
            ->where('agency_id', $agency->id)
            ->permitsMessaging()
            ->exists();
    }

    // -------------------------------------------------------------------------

    private function isOwner(User $user, Creator $creator): bool
    {
        return $creator->user_id === $user->id;
    }

    /**
     * True when the user is an active member of an agency that has a
     * non-blacklisted agency_creator_relations row pointing at this
     * creator. User-wide union across ALL the caller's active agencies —
     * the broad "can this user view the creator at all" question.
     */
    private function hasAgencyAccess(User $user, Creator $creator): bool
    {
        if ($user->type !== UserType::AgencyUser) {
            return false;
        }

        return $this->hasNonBlacklistedRelation($creator, $this->activeAgencyIds($user));
    }

    /**
     * The one canonical blacklist rule (AH-005): does a non-blacklisted
     * relation exist between this creator and ANY of the given agencies?
     * Shared by {@see self::hasAgencyAccess()} (user-wide union) and
     * {@see self::canSeeContactDetails()} (single agency) so the
     * "non-blacklisted relation" predicate lives in exactly one place and
     * the two surfaces can never drift on what "blacklisted" means.
     *
     * @param  list<int>  $agencyIds
     */
    private function hasNonBlacklistedRelation(Creator $creator, array $agencyIds): bool
    {
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
