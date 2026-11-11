<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Services;

use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Enums\CampaignStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Creators\Features\CampaignBrandExclusivityEnabled;
use App\Modules\Creators\Models\Creator;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

/**
 * Brand exclusivity (AH-120) — the SINGLE source of truth for "is this creator
 * selected on a campaign that blocks the brand of the campaign they are
 * looking at?".
 *
 * Creator X is blocked from campaigns of brand B when there is an assignment A
 * of X on a campaign Z whose exclusive-brand list names B, and the block holds:
 *
 *   1. A is in a SELECTED state ({@see AssignmentStatus::countsAsSelected()}:
 *      accepted and every later active state; invited and countered never
 *      block; declined, rejected and cancelled end the block at once), and
 *      neither A nor Z is soft-deleted;
 *   2. Z is not `cancelled` (a cancelled campaign always ends its block);
 *   3. then, by Z's end date:
 *        - date SET: it blocks while `today(UTC) <= exclusivity_ends_on`
 *          (inclusive to the end of the day), whatever the assignment's or the
 *          campaign's status: an explicit date wins, even past completion;
 *        - date NULL: it blocks while A is not in a completed state
 *          ({@see AssignmentStatus::isCompletedForExclusivity()}) AND Z is not
 *          `completed`.
 *
 * ── One rule, three consumers, ONE query shape ──────────────────────────────
 *
 * The rule is spelled out ONCE, in {@see blockingAssignments()}. Everything
 * else composes it, so the PHP answer, the agency's bulk read and the
 * job-posted fan-out's SQL leg cannot drift:
 *
 *   - {@see blockedBrandIdsFor()}   one query per creator (detail, apply);
 *   - {@see conflictsFor()}         one query per page of creators (agency);
 *   - {@see excludeBlockedCreators()}  a NOT EXISTS leg over a creators query
 *                                   (the fan-out), parity-tested against
 *                                   {@see isBlocked()}.
 *
 * It is NOT a leg of {@see JobsBoardVisibility::visibleTo()}: the blocked job
 * must stay visible. Flag OFF answers "not blocked" everywhere without
 * touching the database.
 */
final class BrandExclusivity
{
    public function enabled(): bool
    {
        return Feature::active(CampaignBrandExclusivityEnabled::NAME);
    }

    /**
     * The brand ids this creator may not apply to right now. ONE query; a
     * handful of ids at most.
     *
     * @return list<int>
     */
    public function blockedBrandIdsFor(Creator $creator): array
    {
        if (! $this->enabled()) {
            return [];
        }

        $ids = $this->blockingAssignments(DB::query())
            ->where('a.creator_id', $creator->id)
            ->distinct()
            ->pluck('x.brand_id');

        return array_values(array_map(static fn (mixed $id): int => (int) $id, $ids->all()));
    }

    /**
     * May this creator NOT apply to this campaign because they are selected on
     * another campaign that blocks its brand?
     */
    public function isBlocked(Campaign $campaign, Creator $creator): bool
    {
        return in_array($campaign->brand_id, $this->blockedBrandIdsFor($creator), true);
    }

    /**
     * For a page of creators: which of them are blocked from this campaign's
     * brand, and by which campaign (the earliest, so the answer is stable).
     * ONE query. Agency-side only: the campaign name is the agency's own data.
     *
     * @param  list<int>  $creatorIds
     * @return array<int, array{campaign_ulid: string, campaign_name: string}> creator id => blocking campaign
     */
    public function conflictsFor(Campaign $campaign, array $creatorIds): array
    {
        if ($creatorIds === [] || ! $this->enabled()) {
            return [];
        }

        $rows = $this->blockingAssignments(DB::query())
            // Raw query builder: no tenancy scope applies, so the agency is
            // asserted here. A list can only hold its own agency's brands, so this
            // is already true by construction; it is stated rather than assumed,
            // because the campaign NAME crosses to the agency.
            ->where('z.agency_id', $campaign->agency_id)
            ->whereIn('a.creator_id', $creatorIds)
            ->where('x.brand_id', $campaign->brand_id)
            ->orderBy('z.id')
            ->get(['a.creator_id', 'z.ulid as campaign_ulid', 'z.name as campaign_name']);

        $out = [];
        foreach ($rows as $row) {
            $creatorId = (int) $row->creator_id;

            // Ordered by campaign id: the first row per creator is the earliest.
            $out[$creatorId] ??= [
                'campaign_ulid' => (string) $row->campaign_ulid,
                'campaign_name' => (string) $row->campaign_name,
            ];
        }

        return $out;
    }

    /**
     * The SQL mirror, for a query over `creators`: drop every creator who is
     * blocked from this campaign's brand. A no-op while the flag is off.
     *
     * @param  EloquentBuilder<Creator>|QueryBuilder  $creators
     */
    public function excludeBlockedCreators(EloquentBuilder|QueryBuilder $creators, Campaign $campaign, string $creatorIdColumn = 'creators.id'): void
    {
        if (! $this->enabled()) {
            return;
        }

        $creators->whereNotExists(function (QueryBuilder $sub) use ($campaign, $creatorIdColumn): void {
            $this->blockingAssignments($sub)
                ->whereColumn('a.creator_id', $creatorIdColumn)
                ->where('x.brand_id', $campaign->brand_id)
                ->selectRaw('1');
        });
    }

    /**
     * THE RULE. Assignments (alias `a`) on campaigns (`z`) whose exclusive list
     * (`x`) is in force today. Callers add the creator and the brand.
     *
     * Statuses come from the enum, never from literals here, so a status added
     * later is classified once (and the classification spec fails until it is).
     */
    private function blockingAssignments(QueryBuilder $query): QueryBuilder
    {
        $selected = $this->statuses(static fn (AssignmentStatus $s): bool => $s->countsAsSelected());
        $completed = $this->statuses(static fn (AssignmentStatus $s): bool => $s->isCompletedForExclusivity());
        $today = Carbon::now('UTC')->toDateString();

        return $query
            ->from('campaign_assignments as a')
            ->join('campaigns as z', 'z.id', '=', 'a.campaign_id')
            ->join('campaign_exclusive_brands as x', 'x.campaign_id', '=', 'z.id')
            ->whereNull('a.deleted_at')
            ->whereNull('z.deleted_at')
            ->whereIn('a.status', $selected)
            ->where('z.status', '<>', CampaignStatus::Cancelled->value)
            ->where(function (QueryBuilder $rule) use ($completed, $today): void {
                // Date set: the date wins.
                $rule->where(function (QueryBuilder $dated) use ($today): void {
                    $dated->whereNotNull('z.exclusivity_ends_on')
                        ->whereDate('z.exclusivity_ends_on', '>=', $today);
                })
                    // No date: the block ends at completion (assignment or campaign).
                    ->orWhere(function (QueryBuilder $open) use ($completed): void {
                        $open->whereNull('z.exclusivity_ends_on')
                            ->whereNotIn('a.status', $completed)
                            ->where('z.status', '<>', CampaignStatus::Completed->value);
                    });
            });
    }

    /**
     * @param  callable(AssignmentStatus): bool  $in
     * @return list<string>
     */
    private function statuses(callable $in): array
    {
        return array_values(array_map(
            static fn (AssignmentStatus $s): string => $s->value,
            array_filter(AssignmentStatus::cases(), $in),
        ));
    }
}
