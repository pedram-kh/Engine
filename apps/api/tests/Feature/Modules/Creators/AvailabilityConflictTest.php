<?php

declare(strict_types=1);

use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Enums\BlockType;
use App\Modules\Creators\Models\CreatorAvailabilityBlock;
use App\Modules\Creators\Services\Availability\AvailabilityConflictService;
use App\Modules\Creators\Services\Availability\AvailabilityExpansionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Sprint 5 Chunk A — hard-block conflict-detection (D-a5), detection only.
 *
 * A HARD block (one-off OR expanded-recurring) overlapping a range is a
 * conflict; a SOFT block is not (§5.35 break-revert: flip the
 * BlockType::Hard filter in AvailabilityConflictService and the soft-block
 * "no conflict" test below fails). The service consumes the single
 * AvailabilityExpansionService (D-a4), proven by the agreement test.
 */
function conflictService(): AvailabilityConflictService
{
    return app(AvailabilityConflictService::class);
}

function conflictRange(): array
{
    return [
        CarbonImmutable::parse('2026-06-10T00:00:00+00:00'),
        CarbonImmutable::parse('2026-06-12T00:00:00+00:00'),
    ];
}

it('detects a one-off HARD block overlapping the range', function (): void {
    $creator = CreatorFactory::new()->createOne();
    CreatorAvailabilityBlock::factory()->for($creator)->hard()->create([
        'starts_at' => '2026-06-11T09:00:00+00:00',
        'ends_at' => '2026-06-11T17:00:00+00:00',
        'is_recurring' => false,
    ]);

    [$from, $to] = conflictRange();
    $result = conflictService()->detect($creator, $from, $to);

    expect($result->hasConflict)->toBeTrue()
        ->and($result->conflicts)->toHaveCount(1);
});

it('detects an expanded-recurring HARD block overlapping the range', function (): void {
    $creator = CreatorFactory::new()->createOne();
    // Weekly Thursday; 2026-06-11 is a Thursday and falls in the range.
    CreatorAvailabilityBlock::factory()->for($creator)->hard()->weeklyRecurring('FREQ=WEEKLY;BYDAY=TH')->create([
        'starts_at' => '2026-06-04T09:00:00+00:00',
        'ends_at' => '2026-06-04T17:00:00+00:00',
    ]);

    [$from, $to] = conflictRange();
    $result = conflictService()->detect($creator, $from, $to);

    expect($result->hasConflict)->toBeTrue();
});

it('does NOT treat a SOFT block as a conflict (break-revert anchor)', function (): void {
    $creator = CreatorFactory::new()->createOne();
    CreatorAvailabilityBlock::factory()->for($creator)->soft()->create([
        'starts_at' => '2026-06-11T09:00:00+00:00',
        'ends_at' => '2026-06-11T17:00:00+00:00',
        'is_recurring' => false,
    ]);

    [$from, $to] = conflictRange();
    $result = conflictService()->detect($creator, $from, $to);

    expect($result->hasConflict)->toBeFalse()
        ->and($result->conflicts)->toBeEmpty();
});

it('reports no conflict when no block overlaps the range', function (): void {
    $creator = CreatorFactory::new()->createOne();
    CreatorAvailabilityBlock::factory()->for($creator)->hard()->create([
        'starts_at' => '2026-07-01T09:00:00+00:00',
        'ends_at' => '2026-07-01T17:00:00+00:00',
        'is_recurring' => false,
    ]);

    [$from, $to] = conflictRange();
    $result = conflictService()->detect($creator, $from, $to);

    expect($result->hasConflict)->toBeFalse();
});

// ---------------------------------------------------------------------------
// Single expansion source (D-a4): conflict-detection agrees with expansion
// ---------------------------------------------------------------------------

it('conflict-detection reads the SAME expansion output (single source)', function (): void {
    $creator = CreatorFactory::new()->createOne();

    // One-off hard, recurring hard, and a soft (which expansion lists but
    // conflict-detection must exclude).
    CreatorAvailabilityBlock::factory()->for($creator)->hard()->create([
        'starts_at' => '2026-06-11T09:00:00+00:00',
        'ends_at' => '2026-06-11T17:00:00+00:00',
        'is_recurring' => false,
    ]);
    CreatorAvailabilityBlock::factory()->for($creator)->hard()->weeklyRecurring('FREQ=WEEKLY;BYDAY=TH')->create([
        'starts_at' => '2026-06-04T09:00:00+00:00',
        'ends_at' => '2026-06-04T17:00:00+00:00',
    ]);
    CreatorAvailabilityBlock::factory()->for($creator)->soft()->create([
        'starts_at' => '2026-06-10T09:00:00+00:00',
        'ends_at' => '2026-06-10T17:00:00+00:00',
        'is_recurring' => false,
    ]);

    [$from, $to] = conflictRange();

    $expanded = app(AvailabilityExpansionService::class)->expand($creator, $from, $to);
    $hardFromExpansion = array_values(array_filter(
        $expanded,
        fn ($o) => $o->block->block_type === BlockType::Hard,
    ));

    $conflicts = conflictService()->detect($creator, $from, $to)->conflicts;

    // Conflict-detection's hard set is exactly the expansion's hard set —
    // same instants, same order. No independent re-expansion.
    expect(array_map(fn ($o) => $o->startsAt->toIso8601String(), $conflicts))
        ->toBe(array_map(fn ($o) => $o->startsAt->toIso8601String(), $hardFromExpansion));
});
