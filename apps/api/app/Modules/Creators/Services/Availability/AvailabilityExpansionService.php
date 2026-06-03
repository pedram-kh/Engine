<?php

declare(strict_types=1);

namespace App\Modules\Creators\Services\Availability;

use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Models\CreatorAvailabilityBlock;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use RRule\RRule;

/**
 * Expands a creator's availability blocks into concrete occurrences within
 * a date window (D-a3/D-a4).
 *
 * This is the SINGLE source of expanded occurrences. Conflict-detection
 * ({@see AvailabilityConflictService}), the agency read-view, and the
 * Sprint 5 Chunk B creator calendar all read this output — none of them
 * re-expand independently, so there is no client/server (or service/service)
 * drift in what "the creator's availability for this window" means.
 *
 *   - One-off blocks (is_recurring = false): emitted as-is when they
 *     overlap the window, fetched via the (creator_id, starts_at, ends_at)
 *     index.
 *   - Weekly-recurring blocks: expanded with rlanvin/php-rrule's
 *     getOccurrencesBetween(). Each occurrence keeps the source block's
 *     duration (ends_at - starts_at); the RRULE supplies only the start
 *     dates. The query window is widened backward by the block duration so
 *     an occurrence that *starts* before the window but is still ongoing
 *     inside it is not missed (rlanvin returns events by start instant).
 */
final class AvailabilityExpansionService
{
    /**
     * @return list<AvailabilityOccurrence>
     */
    public function expand(Creator $creator, CarbonInterface $windowStart, CarbonInterface $windowEnd): array
    {
        $start = CarbonImmutable::instance($windowStart);
        $end = CarbonImmutable::instance($windowEnd);

        $occurrences = [];

        foreach ($this->oneOffBlocks($creator, $start, $end) as $block) {
            $occurrences[] = new AvailabilityOccurrence(
                $block,
                CarbonImmutable::instance($block->starts_at),
                CarbonImmutable::instance($block->ends_at),
            );
        }

        foreach ($this->recurringBlocks($creator) as $block) {
            foreach ($this->expandRecurring($block, $start, $end) as $occurrence) {
                $occurrences[] = $occurrence;
            }
        }

        usort(
            $occurrences,
            static fn (AvailabilityOccurrence $a, AvailabilityOccurrence $b): int => $a->startsAt <=> $b->startsAt,
        );

        return $occurrences;
    }

    /**
     * @return list<AvailabilityOccurrence>
     */
    private function expandRecurring(
        CreatorAvailabilityBlock $block,
        CarbonImmutable $windowStart,
        CarbonImmutable $windowEnd,
    ): array {
        $rule = $block->recurrence_rule;
        if ($rule === null || $rule === '') {
            return [];
        }

        $durationSeconds = $block->ends_at->getTimestamp() - $block->starts_at->getTimestamp();
        $durationSeconds = max(0, $durationSeconds);

        // dtstart comes from the block, never the stored rule. Widen the
        // begin bound by the duration so an occurrence that started before
        // the window but overlaps into it is still returned.
        $rrule = new RRule($rule, $block->starts_at->toDateTimeImmutable());
        $queryStart = $windowStart->subSeconds($durationSeconds);

        $occurrences = [];

        foreach ($rrule->getOccurrencesBetween($queryStart, $windowEnd) as $occurrenceStart) {
            $startsAt = CarbonImmutable::instance($occurrenceStart);
            $endsAt = $startsAt->addSeconds($durationSeconds);

            $occurrence = new AvailabilityOccurrence($block, $startsAt, $endsAt);

            if ($occurrence->overlaps($windowStart, $windowEnd)) {
                $occurrences[] = $occurrence;
            }
        }

        return $occurrences;
    }

    /**
     * One-off blocks overlapping the window. Uses the
     * idx_availability_creator_dates index: a block overlaps when it starts
     * before the window ends AND ends after the window starts.
     *
     * @return iterable<CreatorAvailabilityBlock>
     */
    private function oneOffBlocks(Creator $creator, CarbonImmutable $windowStart, CarbonImmutable $windowEnd): iterable
    {
        return $creator->availabilityBlocks()
            ->where('is_recurring', false)
            ->where('starts_at', '<', $windowEnd)
            ->where('ends_at', '>', $windowStart)
            ->get();
    }

    /**
     * All recurring blocks for the creator. There is no date predicate that
     * safely bounds a recurrence rule, so we load them all (a creator has a
     * handful at most) and let the RRULE engine bound by the window.
     *
     * @return iterable<CreatorAvailabilityBlock>
     */
    private function recurringBlocks(Creator $creator): iterable
    {
        return $creator->availabilityBlocks()
            ->where('is_recurring', true)
            ->whereNotNull('recurrence_rule')
            ->get();
    }
}
