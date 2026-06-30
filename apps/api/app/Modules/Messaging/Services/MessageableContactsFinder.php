<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Creators\Enums\ApplicationStatus;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Policies\CreatorPolicy;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * AH-012 (D3) — the SET-valued half of the messaging gate. Answers
 * "every creator this agency can message" / "every agency this creator can
 * message" and MUST agree, contact-for-contact, with the single-pair
 * {@see CreatorPolicy::canMessageRelationship()}.
 *
 * Agreement is structural, not coincidental: the relation leg is the SAME
 * `AgencyCreatorRelation::scopePermitsMessaging()` (roster + non-blacklisted)
 * the policy now uses, and the creator-`approved` leg is applied identically on
 * both sides. The agreement test pins this; the break-revert that proves its
 * teeth is a finder predicate that DIVERGES from the shared scope (e.g. inline a
 * narrower predicate dropping the roster leg here) — the agreement test then
 * fails because a non-roster contact enters the set the single-pair gate rejects.
 *
 * These finders deliberately carry NO send authorization of their own — they
 * compute the eligible SET; the controller still enforces who may call them
 * (agency membership via tenancy.agency; the owning creator via creators/me).
 */
final class MessageableContactsFinder
{
    /**
     * The creators this agency may currently message — paginated + name-searched
     * for the agency-side contact picker (D6: rosters can be large). Includes
     * contacts with AND without an existing thread (WhatsApp, Q4); the thread is
     * provisioned lazily on first send (D1).
     *
     * @return LengthAwarePaginator<int, AgencyCreatorRelation>
     */
    public function creatorsForAgency(Agency $agency, ?string $search, int $perPage, int $page): LengthAwarePaginator
    {
        $query = AgencyCreatorRelation::query()
            // Belt-and-suspenders on top of the BelongsToAgency global scope
            // (the roster-index precedent) — never another agency's relations.
            ->where('agency_creator_relations.agency_id', $agency->id)
            // The relation leg — shared with the single-pair gate (D3).
            ->permitsMessaging()
            // The creator-`approved` leg + the optional name search. whereHas
            // also enforces a non-soft-deleted creator exists.
            ->whereHas('creator', function (Builder $creatorQuery) use ($search): void {
                $creatorQuery->where('application_status', ApplicationStatus::Approved->value);

                if ($search !== null && $search !== '') {
                    // Simple case-insensitive substring on display_name (D3 of
                    // kickoff answers — NOT the roster's driver-aware FTS).
                    $creatorQuery->whereRaw('LOWER(display_name) LIKE ?', ['%'.mb_strtolower($search).'%']);
                }
            })
            ->with(['creator:id,ulid,display_name,avatar_path'])
            // display_name ASC via a correlated subquery (the roster precedent —
            // avoids a join + hydration clobber), stable id tiebreaker.
            ->orderBy(
                Creator::query()
                    ->select('display_name')
                    ->whereColumn('creators.id', 'agency_creator_relations.creator_id'),
            )
            ->orderBy('agency_creator_relations.id');

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * The agencies this creator may currently message — the creator-side picker
     * source. The list is small (a handful), so it is unpaginated (D6).
     *
     * The creator is the VIEWER, so the `approved` leg is about themselves: an
     * unapproved creator can message no one, so the set is empty — keeping exact
     * agreement with the single-pair gate (which returns false for every agency
     * when the creator is not approved).
     *
     * @return Collection<int, Agency>
     */
    public function agenciesForCreator(Creator $creator): Collection
    {
        if ($creator->application_status !== ApplicationStatus::Approved) {
            return collect();
        }

        return AgencyCreatorRelation::query()
            // The caller is a CREATOR who may relate to many agencies — the
            // ambient tenant context must not narrow the set (the creators/me
            // bypass precedent). Ownership is structural via creator_id.
            ->withoutGlobalScope(BelongsToAgencyScope::class)
            ->where('creator_id', $creator->id)
            ->permitsMessaging()
            ->with('agency:id,ulid,name,logo_path')
            ->get()
            ->map(static fn (AgencyCreatorRelation $relation): Agency => $relation->agency)
            ->filter()
            ->values();
    }
}
