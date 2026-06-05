<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Services;

use App\Modules\Agencies\Enums\BlacklistType;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Services\Availability\AvailabilityConflictResult;
use App\Modules\Creators\Services\Availability\AvailabilityConflictService;
use Illuminate\Support\Facades\DB;

/**
 * The TWO-TIER invite gate (Sprint 8 Chunk 2, D-1/D-2) — two DIFFERENT axes
 * with DIFFERENT severities, kept deliberately distinct:
 *
 *   - {@see isHardBlacklisted()}  — a HARD BLOCK (the caller returns 422).
 *     Composes BOTH hard-blacklist predicates; EITHER match excludes. Soft
 *     (either scope) does NOT block.
 *   - {@see availabilityConflict()} — a SOFT WARN (the caller returns a 409
 *     that the agency re-submits with `acknowledged: true` to proceed). Soft
 *     availability is not a conflict at all.
 *
 * Blacklist = hard 422; availability = soft 409-then-acknowledge. The tiers
 * must never collapse into one severity.
 */
final class AssignmentInviteGate
{
    public function __construct(
        private readonly AvailabilityConflictService $availability,
    ) {}

    /**
     * D-1 — true when EITHER hard-blacklist predicate matches:
     *
     *   - Agency-wide hard: an `agency_creator_relations` row for this creator
     *     under the CAMPAIGN's agency with `is_blacklisted = true` AND
     *     `blacklist_type = 'hard'` (the `excludeHardBlacklisted` /
     *     connection-gate shape — works WITHOUT a loaded relation, since invite
     *     is first-contact-capable, D-4).
     *   - Brand-scoped hard (the deferred promise comes due): a non-soft-deleted
     *     `brand_creator_blacklists` row for (campaign.brand_id, creator_id)
     *     with `blacklist_type = 'hard'`. Keyed by brand only — excludes from
     *     THIS brand's campaign, nothing else.
     */
    public function isHardBlacklisted(Campaign $campaign, int $creatorId): bool
    {
        $agencyWide = DB::table('agency_creator_relations')
            ->where('creator_id', $creatorId)
            ->where('agency_id', $campaign->agency_id)
            ->where('is_blacklisted', true)
            ->where('blacklist_type', BlacklistType::Hard->value)
            ->exists();

        if ($agencyWide) {
            return true;
        }

        return DB::table('brand_creator_blacklists')
            ->where('brand_id', $campaign->brand_id)
            ->where('creator_id', $creatorId)
            ->where('blacklist_type', BlacklistType::Hard->value)
            ->whereNull('deleted_at')
            ->exists();
    }

    /**
     * D-2 — a HARD availability conflict over the campaign's posting window
     * (falling back to the campaign run dates when the posting window is null).
     * Returns a result with no conflict when the campaign has no dateable
     * window — there is nothing to warn about.
     */
    public function availabilityConflict(Campaign $campaign, Creator $creator): AvailabilityConflictResult
    {
        $startsAt = $campaign->posting_window_starts_at ?? $campaign->starts_at;
        $endsAt = $campaign->posting_window_ends_at ?? $campaign->ends_at;

        if ($startsAt === null || $endsAt === null) {
            return new AvailabilityConflictResult(false, []);
        }

        return $this->availability->detect($creator, $startsAt, $endsAt);
    }
}
