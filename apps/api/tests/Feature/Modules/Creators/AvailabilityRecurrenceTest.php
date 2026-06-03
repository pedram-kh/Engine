<?php

declare(strict_types=1);

use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Models\CreatorAvailabilityBlock;
use App\Modules\Creators\Services\Availability\AvailabilityExpansionService;
use App\Modules\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Sprint 5 Chunk A — weekly recurrence expansion + the weekly-only ceiling.
 *
 * The ceiling (D-a3, plan-pause Q1 = allow INTERVAL): FREQ=WEEKLY + optional
 * INTERVAL + optional BYDAY + optional UNTIL. Everything else is rejected at
 * validation (§5.35 break-revert: drop the WeeklyRecurrenceRule guard and a
 * FREQ=DAILY slips into storage + expansion).
 */

/** 2026-06-01 is a Monday — a convenient weekly anchor. */
function expansionService(): AvailabilityExpansionService
{
    return app(AvailabilityExpansionService::class);
}

// ---------------------------------------------------------------------------
// Expansion correctness (break-revert: a wrong count fails)
// ---------------------------------------------------------------------------

it('expands a weekly Monday rule to the correct occurrences in a window', function (): void {
    $creator = CreatorFactory::new()->createOne();
    CreatorAvailabilityBlock::factory()->for($creator)->weeklyRecurring('FREQ=WEEKLY;BYDAY=MO')->create([
        'starts_at' => '2026-06-01T09:00:00+00:00',
        'ends_at' => '2026-06-01T17:00:00+00:00',
    ]);

    $occurrences = expansionService()->expand(
        $creator,
        CarbonImmutable::parse('2026-06-01T00:00:00+00:00'),
        CarbonImmutable::parse('2026-06-30T00:00:00+00:00'),
    );

    // Mondays in [Jun 1, Jun 30): 1, 8, 15, 22, 29 → 5 occurrences.
    expect($occurrences)->toHaveCount(5);
    $starts = array_map(fn ($o) => $o->startsAt->toDateString(), $occurrences);
    expect($starts)->toBe(['2026-06-01', '2026-06-08', '2026-06-15', '2026-06-22', '2026-06-29']);
});

it('honours INTERVAL (every other week) — the Q1 ceiling decision', function (): void {
    $creator = CreatorFactory::new()->createOne();
    CreatorAvailabilityBlock::factory()->for($creator)->weeklyRecurring('FREQ=WEEKLY;INTERVAL=2;BYDAY=MO')->create([
        'starts_at' => '2026-06-01T09:00:00+00:00',
        'ends_at' => '2026-06-01T17:00:00+00:00',
    ]);

    $occurrences = expansionService()->expand(
        $creator,
        CarbonImmutable::parse('2026-06-01T00:00:00+00:00'),
        CarbonImmutable::parse('2026-07-15T00:00:00+00:00'),
    );

    // Every 2 weeks from Jun 1: Jun 1, 15, 29, Jul 13 → 4 occurrences.
    expect($occurrences)->toHaveCount(4);
    $starts = array_map(fn ($o) => $o->startsAt->toDateString(), $occurrences);
    expect($starts)->toBe(['2026-06-01', '2026-06-15', '2026-06-29', '2026-07-13']);
});

it('stops a recurring rule at its UNTIL bound', function (): void {
    $creator = CreatorFactory::new()->createOne();
    // UNTIL is an instant; set it after the Jun 15 occurrence's 09:00 start
    // so Jun 15 is included and Jun 22 is excluded.
    CreatorAvailabilityBlock::factory()->for($creator)->weeklyRecurring('FREQ=WEEKLY;BYDAY=MO;UNTIL=20260615T120000Z')->create([
        'starts_at' => '2026-06-01T09:00:00+00:00',
        'ends_at' => '2026-06-01T17:00:00+00:00',
    ]);

    $occurrences = expansionService()->expand(
        $creator,
        CarbonImmutable::parse('2026-06-01T00:00:00+00:00'),
        CarbonImmutable::parse('2026-06-30T00:00:00+00:00'),
    );

    // UNTIL noon 2026-06-15 → Jun 1, 8, 15 only (Jun 22 is past the bound).
    expect($occurrences)->toHaveCount(3);
});

it('keeps a one-off block as a single occurrence, unaffected by recurrence', function (): void {
    $creator = CreatorFactory::new()->createOne();
    CreatorAvailabilityBlock::factory()->for($creator)->create([
        'starts_at' => '2026-06-10T09:00:00+00:00',
        'ends_at' => '2026-06-12T17:00:00+00:00',
        'is_recurring' => false,
    ]);

    $occurrences = expansionService()->expand(
        $creator,
        CarbonImmutable::parse('2026-06-01T00:00:00+00:00'),
        CarbonImmutable::parse('2026-06-30T00:00:00+00:00'),
    );

    expect($occurrences)->toHaveCount(1);
});

// ---------------------------------------------------------------------------
// Weekly-only validation ceiling (endpoint level, break-revert)
// ---------------------------------------------------------------------------

it('accepts a weekly+INTERVAL+BYDAY rule on create', function (): void {
    $user = User::factory()->create();
    CreatorFactory::new()->createOne(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson('/api/v1/creators/me/availability', [
            'starts_at' => '2026-06-01T09:00:00+00:00',
            'ends_at' => '2026-06-01T17:00:00+00:00',
            'block_type' => 'hard',
            'kind' => 'exclusive_contract',
            'is_recurring' => true,
            'recurrence_rule' => 'FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,WE',
        ])
        ->assertCreated()
        ->assertJsonPath('data.attributes.is_recurring', true)
        ->assertJsonPath('data.attributes.recurrence_rule', 'FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,WE');
});

it('rejects a FREQ=DAILY rule (the weekly ceiling, break-revert)', function (): void {
    $user = User::factory()->create();
    CreatorFactory::new()->createOne(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson('/api/v1/creators/me/availability', [
            'starts_at' => '2026-06-01T09:00:00+00:00',
            'ends_at' => '2026-06-01T17:00:00+00:00',
            'block_type' => 'hard',
            'kind' => 'vacation',
            'is_recurring' => true,
            'recurrence_rule' => 'FREQ=DAILY',
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.source.pointer', '/data/attributes/recurrence_rule');
});

it('rejects a monthly BYMONTHDAY rule', function (): void {
    $user = User::factory()->create();
    CreatorFactory::new()->createOne(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson('/api/v1/creators/me/availability', [
            'starts_at' => '2026-06-01T09:00:00+00:00',
            'ends_at' => '2026-06-01T17:00:00+00:00',
            'block_type' => 'hard',
            'kind' => 'vacation',
            'is_recurring' => true,
            'recurrence_rule' => 'FREQ=MONTHLY;BYMONTHDAY=1',
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.source.pointer', '/data/attributes/recurrence_rule');
});

it('rejects a numeric-prefixed BYDAY (monthly nth-weekday pattern)', function (): void {
    $user = User::factory()->create();
    CreatorFactory::new()->createOne(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson('/api/v1/creators/me/availability', [
            'starts_at' => '2026-06-01T09:00:00+00:00',
            'ends_at' => '2026-06-01T17:00:00+00:00',
            'block_type' => 'hard',
            'kind' => 'vacation',
            'is_recurring' => true,
            'recurrence_rule' => 'FREQ=WEEKLY;BYDAY=2MO',
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.source.pointer', '/data/attributes/recurrence_rule');
});

it('requires a rule when is_recurring is true', function (): void {
    $user = User::factory()->create();
    CreatorFactory::new()->createOne(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson('/api/v1/creators/me/availability', [
            'starts_at' => '2026-06-01T09:00:00+00:00',
            'ends_at' => '2026-06-01T17:00:00+00:00',
            'block_type' => 'hard',
            'kind' => 'vacation',
            'is_recurring' => true,
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.source.pointer', '/data/attributes/recurrence_rule');
});
