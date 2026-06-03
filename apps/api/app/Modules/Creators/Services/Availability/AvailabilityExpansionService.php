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

        return $this->assemble(
            $this->oneOffBlocks($creator, $start, $end),
            $this->recurringBlocks($creator),
            $start,
            $end,
        );
    }

    /**
     * Batch counterpart to {@see self::expand()} for a SET of creators
     * (D-6-4). The roster availability filter needs every creator in the
     * filtered set expanded to decide who is free — calling expand() in a
     * loop is a 2N query fan-out. This loads ALL creators' one-off + recurring
     * blocks in exactly TWO queries (a `creator_id IN (...)` each), groups them
     * in PHP, then runs the SAME per-creator {@see self::assemble()} the single
     * expand() uses.
     *
     * CRITICAL: this produces IDENTICAL per-creator results to calling
     * expand() per creator — it is the same logic batched at the query layer,
     * not a reimplementation (the assemble()/expandRecurring() code path is
     * shared). The §5.17 batch == loop test pins this.
     *
     * @param  list<int>  $creatorIds
     * @return array<int, list<AvailabilityOccurrence>> keyed by creator id
     */
    public function expandMany(array $creatorIds, CarbonInterface $windowStart, CarbonInterface $windowEnd): array
    {
        $ids = array_values(array_unique($creatorIds));
        if ($ids === []) {
            return [];
        }

        $start = CarbonImmutable::instance($windowStart);
        $end = CarbonImmutable::instance($windowEnd);

        // Query 1: one-off blocks overlapping the window for every creator.
        // Same overlap predicate as the single-creator oneOffBlocks().
        $oneOffByCreator = CreatorAvailabilityBlock::query()
            ->whereIn('creator_id', $ids)
            ->where('is_recurring', false)
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->get()
            ->groupBy('creator_id');

        // Query 2: all recurring blocks for every creator (no date predicate
        // safely bounds a rule — the RRULE engine bounds by the window).
        $recurringByCreator = CreatorAvailabilityBlock::query()
            ->whereIn('creator_id', $ids)
            ->where('is_recurring', true)
            ->whereNotNull('recurrence_rule')
            ->get()
            ->groupBy('creator_id');

        $result = [];
        foreach ($ids as $id) {
            $result[$id] = $this->assemble(
                $oneOffByCreator->get($id) ?? [],
                $recurringByCreator->get($id) ?? [],
                $start,
                $end,
            );
        }

        return $result;
    }

    /**
     * Assemble the sorted occurrence list for ONE creator from its one-off +
     * recurring blocks. The single source of per-creator expansion logic,
     * shared by expand() (per-creator queries) and expandMany() (batched
     * queries) so the two can never drift (D-6-4).
     *
     * @param  iterable<CreatorAvailabilityBlock>  $oneOffBlocks
     * @param  iterable<CreatorAvailabilityBlock>  $recurringBlocks
     * @return list<AvailabilityOccurrence>
     */
    private function assemble(
        iterable $oneOffBlocks,
        iterable $recurringBlocks,
        CarbonImmutable $windowStart,
        CarbonImmutable $windowEnd,
    ): array {
        $occurrences = [];

        foreach ($oneOffBlocks as $block) {
            $occurrences[] = new AvailabilityOccurrence(
                $block,
                CarbonImmutable::instance($block->starts_at),
                CarbonImmutable::instance($block->ends_at),
            );
        }

        foreach ($recurringBlocks as $block) {
            foreach ($this->expandRecurring($block, $windowStart, $windowEnd) as $occurrence) {
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
