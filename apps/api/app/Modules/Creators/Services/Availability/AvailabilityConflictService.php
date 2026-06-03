<?php

declare(strict_types=1);

namespace App\Modules\Creators\Services\Availability;

use App\Modules\Creators\Enums\BlockType;
use App\Modules\Creators\Models\Creator;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Detects whether any HARD availability block overlaps a given date range
 * (D-a5).
 *
 * Detection ONLY — no modal, no invite-flow wiring. The agency
 * invite-to-assignment surface this attaches to does not exist yet
 * (campaign_assignments is Sprint 8); this service ships standalone +
 * unit-tested now so Sprint 8 only wires the trigger.
 *
 *   - HARD blocks (one-off OR expanded-recurring) overlapping the range
 *     ARE conflicts.
 *   - SOFT blocks are NOT conflicts (warning-only, surfaced separately).
 *
 * Consumes {@see AvailabilityExpansionService} — it does NOT re-expand
 * recurrence itself, so it can never disagree with the agency view about
 * what the creator's availability is for the same window (D-a4).
 */
final class AvailabilityConflictService
{
    public function __construct(
        private readonly AvailabilityExpansionService $expansion,
    ) {}

    public function detect(Creator $creator, CarbonInterface $rangeStart, CarbonInterface $rangeEnd): AvailabilityConflictResult
    {
        $start = CarbonImmutable::instance($rangeStart);
        $end = CarbonImmutable::instance($rangeEnd);

        $conflicts = array_values(array_filter(
            $this->expansion->expand($creator, $start, $end),
            static fn (AvailabilityOccurrence $occurrence): bool => $occurrence->block->block_type === BlockType::Hard,
        ));

        return new AvailabilityConflictResult($conflicts !== [], $conflicts);
    }
}
