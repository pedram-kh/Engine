<?php

declare(strict_types=1);

namespace App\Modules\Creators\Services\Availability;

use App\Modules\Creators\Models\CreatorAvailabilityBlock;
use Carbon\CarbonImmutable;

/**
 * A single concrete occurrence of an availability block within a window.
 *
 * For a one-off block this is the block itself. For a weekly-recurring
 * block this is one expanded instance — the same clock-time + duration as
 * the source block, on a date the RRULE generates. Either way it carries a
 * reference back to the source {@see CreatorAvailabilityBlock} so the two
 * consumers (conflict-detection, the agency view) can read block_type /
 * kind / reason without re-querying.
 *
 * Emitted exclusively by {@see AvailabilityExpansionService} — the single
 * expansion source for conflict-detection, the agency read-view, and
 * (Sprint 5 Chunk B) the creator calendar (D-a4: one code path, no drift).
 */
final readonly class AvailabilityOccurrence
{
    public function __construct(
        public CreatorAvailabilityBlock $block,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
    ) {}

    /**
     * Does this occurrence overlap the half-open-ish range [start, end]?
     * Two intervals overlap when each starts before the other ends.
     */
    public function overlaps(CarbonImmutable $rangeStart, CarbonImmutable $rangeEnd): bool
    {
        return $this->startsAt < $rangeEnd && $this->endsAt > $rangeStart;
    }
}
