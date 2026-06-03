<?php

declare(strict_types=1);

use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Models\CreatorAvailabilityBlock;
use App\Modules\Creators\Services\Availability\AvailabilityExpansionService;
use App\Modules\Creators\Services\Availability\AvailabilityOccurrence;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Sprint 6.5 (D-6-4) — the batch `expandMany()` is a query-layer batching of
 * the SAME per-creator expansion logic the single `expand()` uses (shared
 * `assemble()`/`expandRecurring()` path), NOT a reimplementation.
 *
 * The load-bearing correctness property (the spot-check anchor): for any set
 * of creators, `expandMany()` produces IDENTICAL per-creator occurrences to
 * calling `expand()` on each creator in a loop. Break-revert (§5.35): a batch
 * method that drops, double-counts, or mis-groups a creator's occurrences
 * fails the equality assertion below.
 */
function expansion(): AvailabilityExpansionService
{
    return app(AvailabilityExpansionService::class);
}

/**
 * Canonicalize an occurrence list to a comparable shape (source block id +
 * instants) so batch-vs-loop equality is asserted on the actual occurrences,
 * order included.
 *
 * @param  list<AvailabilityOccurrence>  $occurrences
 * @return list<array{block_id: int, starts_at: string, ends_at: string}>
 */
function fingerprint(array $occurrences): array
{
    return array_map(
        static fn (AvailabilityOccurrence $o): array => [
            'block_id' => $o->block->id,
            'starts_at' => $o->startsAt->toIso8601String(),
            'ends_at' => $o->endsAt->toIso8601String(),
        ],
        $occurrences,
    );
}

it('batch expandMany == per-creator expand() loop for a mixed set (break-revert anchor)', function (): void {
    $windowStart = CarbonImmutable::parse('2026-06-08T00:00:00+00:00');
    $windowEnd = CarbonImmutable::parse('2026-06-15T00:00:00+00:00');

    // Creator A: a one-off hard in-window + a recurring hard whose Thursday
    // expansion (2026-06-11) lands in the window + a one-off OUTSIDE the window.
    $a = CreatorFactory::new()->createOne();
    CreatorAvailabilityBlock::factory()->for($a)->hard()->create([
        'starts_at' => '2026-06-09T09:00:00+00:00',
        'ends_at' => '2026-06-09T17:00:00+00:00',
        'is_recurring' => false,
    ]);
    CreatorAvailabilityBlock::factory()->for($a)->hard()->weeklyRecurring('FREQ=WEEKLY;BYDAY=TH')->create([
        'starts_at' => '2026-06-04T09:00:00+00:00',
        'ends_at' => '2026-06-04T17:00:00+00:00',
    ]);
    CreatorAvailabilityBlock::factory()->for($a)->hard()->create([
        'starts_at' => '2026-07-01T09:00:00+00:00',
        'ends_at' => '2026-07-01T17:00:00+00:00',
        'is_recurring' => false,
    ]);

    // Creator B: a single SOFT one-off in-window (expansion lists it; it is
    // not a conflict, but expandMany must still emit it identically).
    $b = CreatorFactory::new()->createOne();
    CreatorAvailabilityBlock::factory()->for($b)->soft()->create([
        'starts_at' => '2026-06-10T09:00:00+00:00',
        'ends_at' => '2026-06-10T17:00:00+00:00',
        'is_recurring' => false,
    ]);

    // Creator C: no blocks at all (empty list both ways).
    $c = CreatorFactory::new()->createOne();

    $loop = [
        $a->id => fingerprint(expansion()->expand($a, $windowStart, $windowEnd)),
        $b->id => fingerprint(expansion()->expand($b, $windowStart, $windowEnd)),
        $c->id => fingerprint(expansion()->expand($c, $windowStart, $windowEnd)),
    ];

    $batchRaw = expansion()->expandMany([$a->id, $b->id, $c->id], $windowStart, $windowEnd);
    $batch = [
        $a->id => fingerprint($batchRaw[$a->id]),
        $b->id => fingerprint($batchRaw[$b->id]),
        $c->id => fingerprint($batchRaw[$c->id]),
    ];

    expect($batch)->toEqual($loop);

    // Sanity: the mixed set really did expand something (A has 2 in-window
    // occurrences: the one-off + the recurring Thursday), so the equality is
    // not vacuously over empty lists.
    expect($batch[$a->id])->toHaveCount(2);
    expect($batch[$b->id])->toHaveCount(1);
    expect($batch[$c->id])->toBeEmpty();
});

it('runs the batch in exactly two block queries regardless of creator count', function (): void {
    $windowStart = CarbonImmutable::parse('2026-06-08T00:00:00+00:00');
    $windowEnd = CarbonImmutable::parse('2026-06-15T00:00:00+00:00');

    $ids = [];
    foreach (range(1, 5) as $i) {
        $creator = CreatorFactory::new()->createOne();
        CreatorAvailabilityBlock::factory()->for($creator)->hard()->create([
            'starts_at' => '2026-06-10T09:00:00+00:00',
            'ends_at' => '2026-06-10T17:00:00+00:00',
            'is_recurring' => false,
        ]);
        $ids[] = $creator->id;
    }

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    expansion()->expandMany($ids, $windowStart, $windowEnd);

    // One query for one-off blocks + one for recurring blocks — flat in the
    // number of creators (the whole point of the batch path vs a 2N loop).
    expect($queries)->toBe(2);
});

it('returns an empty map for an empty creator-id set without querying', function (): void {
    $windowStart = CarbonImmutable::parse('2026-06-08T00:00:00+00:00');
    $windowEnd = CarbonImmutable::parse('2026-06-15T00:00:00+00:00');

    expect(expansion()->expandMany([], $windowStart, $windowEnd))->toBe([]);
});
