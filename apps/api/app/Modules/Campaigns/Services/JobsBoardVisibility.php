<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Services;

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Agencies\Enums\BlacklistType;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Creators\Enums\ApplicationStatus;
use App\Modules\Creators\Models\Creator;
use App\Modules\Messaging\Services\MessageableContactsFinder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;

/**
 * THE creator-side jobs-board visibility predicate (Jobs Board chunk 3,
 * AH-056, D2 + kickoff C5) — the single source of truth for "may this creator
 * see this job right now?".
 *
 * Every creator-facing jobs-board surface composes its query from
 * {@see visibleTo()}: the board list, the job detail, the apply endpoint's
 * server-side re-validation, and the fan-out's recipient query. They MUST NOT
 * re-derive it. A predicate that is spelled out in four places is a predicate
 * with four chances to lose a leg, and the leg it loses will be a tenancy one.
 *
 * ── The six legs ────────────────────────────────────────────────────────────
 *
 * 1. **Tenancy scope dropped.** `Campaign` is {@see BelongsToAgencyScope}-d,
 *    but the caller is a CREATOR who may be rostered with many agencies and
 *    whose `tenancy.set` context holds no agency. The ambient scope would
 *    silently narrow (or empty) the board, so it is dropped explicitly — the
 *    same visible-in-code-review bypass `CreatorAssignmentController` uses.
 *    The `SoftDeletes` scope is deliberately NOT dropped: a soft-deleted
 *    campaign is invisible, full stop.
 *
 * 2. **The caller is an APPROVED creator.** A non-approved creator gets an
 *    EMPTY board, not an error — the {@see MessageableContactsFinder::agenciesForCreator()}
 *    shape, where "you can reach nobody" is an empty set rather than a 403.
 *    Expressed as an always-false predicate (the `applyStatusFilter`
 *    `whereRaw('1 = 0')` convention) rather than an early `return` of a
 *    different type, so every caller gets a Builder it can keep composing.
 *
 * 3. **The campaign's agency is one this creator is rostered with.** Via
 *    {@see AgencyCreatorRelation::scopePermitsMessaging()} — the SHARED relation
 *    leg (roster + not blacklisted), never re-spelled here. Note this excludes
 *    BOTH hard and soft agency-wide blacklists, which is stricter than the
 *    discover feed (hard only). That extra strictness is correct for a job
 *    board: an agency that has soft-blacklisted a creator should not be
 *    soliciting applications from them. Recorded, not worked around.
 *
 * 4. **The listing is live.** {@see Campaign::scopeListedOnJobsBoard()},
 *    unchanged from AH-054 — the flag AND a non-terminal status.
 *
 * 5. **The listing has not expired.** `ends_at IS NULL OR ends_at >= <UTC start
 *    of today>`. NULL means never-expires (Q4), consistent with the invite
 *    gate's null-window handling and with `ends_at` being deliberately absent
 *    from the AH-054 listing floor — a listable campaign can legitimately have
 *    no end date. Start-of-day, not `now()`, so a job stays visible THROUGH its
 *    end date rather than vanishing mid-morning.
 *
 * 6. **The creator is not HARD-blacklisted for the campaign's BRAND**
 *    (kickoff C5). Mirrors the brand leg of
 *    {@see AssignmentInviteGate::isHardBlacklisted()} exactly, including the
 *    soft-delete guard. HARD ONLY: soft is warn-at-invite semantics and must
 *    not hide jobs. Without this leg the board would solicit an application the
 *    invite gate would then hard-block — the agency could never act on it.
 *
 * ── Index posture ───────────────────────────────────────────────────────────
 *
 * Leg 5's `OR IS NULL` is non-sargable and `campaigns` indexes `ends_at` only
 * as the trailing half of the `(starts_at, ends_at)` pair, so it cannot lead.
 * At current volume this is a non-issue; it is logged in `docs/tech-debt.md` as
 * volume-triggered alongside AH-054's partial-index entry.
 */
final class JobsBoardVisibility
{
    /**
     * The jobs a creator may see, as a composable query.
     *
     * ⚠ Returns campaigns across MANY agencies by design. Anything that adds
     * to this builder must not assume a single tenant.
     *
     * @return Builder<Campaign>
     */
    public function visibleTo(Creator $creator): Builder
    {
        // Leg 1.
        $query = Campaign::query()->withoutGlobalScope(BelongsToAgencyScope::class);

        // Leg 2 — an unapproved caller sees an empty board, never an error.
        if ($creator->application_status !== ApplicationStatus::Approved) {
            return $query->whereRaw('1 = 0');
        }

        // Leg 3 — the shared relation leg, read as ids so the scope stays the
        // one source of truth (its docblock exists to stop consumers
        // re-spelling roster + blacklist and drifting apart).
        $agencyIds = AgencyCreatorRelation::query()
            ->withoutGlobalScope(BelongsToAgencyScope::class)
            ->where('creator_id', $creator->id)
            ->permitsMessaging()
            ->pluck('agency_id')
            ->all();

        $query->whereIn('campaigns.agency_id', $agencyIds);

        // Leg 4.
        $query->listedOnJobsBoard();

        // Leg 5.
        $startOfToday = Carbon::now('UTC')->startOfDay();
        $query->where(function (Builder $inner) use ($startOfToday): void {
            $inner->whereNull('campaigns.ends_at')
                ->orWhere('campaigns.ends_at', '>=', $startOfToday);
        });

        // Leg 6.
        $query->whereNotExists(function (QueryBuilder $sub) use ($creator): void {
            $sub->from('brand_creator_blacklists')
                ->whereColumn('brand_creator_blacklists.brand_id', 'campaigns.brand_id')
                ->where('brand_creator_blacklists.creator_id', $creator->id)
                ->where('brand_creator_blacklists.blacklist_type', BlacklistType::Hard->value)
                ->whereNull('brand_creator_blacklists.deleted_at')
                ->selectRaw('1');
        });

        return $query;
    }

    /**
     * Resolve ONE visible job by its public ULID, or null.
     *
     * Null covers every reason indistinguishably — unlisted, ended, terminal,
     * un-rostered, brand-blacklisted, caller-not-approved, or simply no such
     * campaign — because the caller turns it into a flat 404. That is the
     * discover fail-closed posture (a non-qualifying subject must not be
     * probeable by ULID) and it is also §5.4 non-fingerprinting: one code for
     * many internal reasons.
     */
    public function findVisible(Creator $creator, string $campaignUlid): ?Campaign
    {
        return $this->visibleTo($creator)
            ->where('campaigns.ulid', $campaignUlid)
            ->first();
    }
}
