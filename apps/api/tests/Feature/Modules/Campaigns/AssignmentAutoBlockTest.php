<?php

declare(strict_types=1);

use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Campaigns\Services\CampaignAssignmentStateMachine;
use App\Modules\Creators\Enums\BlockType;
use App\Modules\Creators\Enums\Kind;
use App\Modules\Creators\Models\CreatorAvailabilityBlock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Sprint 8 Chunk 2 — the accept auto-block (D-11/D-12): the FIRST consumer of
 * AssignmentTransitioned. Accepting an assignment auto-creates a HARD /
 * AssignmentAuto availability block over the campaign's posting window, linked
 * via `assignment_id`; the FK is ON DELETE SET NULL.
 *
 * NOT faking the event here — the listener must actually fire.
 */
function machine(): CampaignAssignmentStateMachine
{
    return app(CampaignAssignmentStateMachine::class);
}

it('auto-creates a Hard/AssignmentAuto block linked to assignment_id over the posting window on accept (D-11)', function (): void {
    // Break-revert: remove the listener registration and NO block is created —
    // the next agency's conflict check would not fire.
    $windowStart = now()->addDays(5);
    $windowEnd = now()->addDays(12);
    $campaign = Campaign::factory()->create([
        'posting_window_starts_at' => $windowStart,
        'posting_window_ends_at' => $windowEnd,
    ]);
    $assignment = CampaignAssignment::factory()->status(AssignmentStatus::Invited)->create([
        'campaign_id' => $campaign->id,
    ]);

    machine()->accept($assignment);

    $block = CreatorAvailabilityBlock::query()
        ->where('assignment_id', $assignment->id)
        ->firstOrFail();

    expect($block->creator_id)->toBe($assignment->creator_id)
        ->and($block->block_type)->toBe(BlockType::Hard)
        ->and($block->kind)->toBe(Kind::AssignmentAuto)
        ->and($block->starts_at->toDateString())->toBe($windowStart->toDateString())
        ->and($block->ends_at->toDateString())->toBe($windowEnd->toDateString());
});

it('falls back to the campaign run dates when the posting window is null', function (): void {
    $runStart = now()->addDays(3);
    $runEnd = now()->addDays(9);
    $campaign = Campaign::factory()->create([
        'posting_window_starts_at' => null,
        'posting_window_ends_at' => null,
        'starts_at' => $runStart,
        'ends_at' => $runEnd,
    ]);
    $assignment = CampaignAssignment::factory()->status(AssignmentStatus::Invited)->create([
        'campaign_id' => $campaign->id,
    ]);

    machine()->accept($assignment);

    $block = CreatorAvailabilityBlock::query()->where('assignment_id', $assignment->id)->firstOrFail();
    expect($block->starts_at->toDateString())->toBe($runStart->toDateString())
        ->and($block->ends_at->toDateString())->toBe($runEnd->toDateString());
});

it('skips the auto-block when the campaign has no dateable window (the flagged edge)', function (): void {
    $campaign = Campaign::factory()->create([
        'posting_window_starts_at' => null,
        'posting_window_ends_at' => null,
        'starts_at' => null,
        'ends_at' => null,
    ]);
    $assignment = CampaignAssignment::factory()->status(AssignmentStatus::Invited)->create([
        'campaign_id' => $campaign->id,
    ]);

    machine()->accept($assignment);

    expect(CreatorAvailabilityBlock::query()->where('assignment_id', $assignment->id)->exists())->toBeFalse();
});

it('does NOT auto-block on a non-accept transition (the listener is scoped to assignment.accepted)', function (): void {
    $campaign = Campaign::factory()->create([
        'posting_window_starts_at' => now()->addDays(5),
        'posting_window_ends_at' => now()->addDays(12),
    ]);
    $assignment = CampaignAssignment::factory()->status(AssignmentStatus::Invited)->create([
        'campaign_id' => $campaign->id,
    ]);

    machine()->decline($assignment);

    expect(CreatorAvailabilityBlock::query()->where('assignment_id', $assignment->id)->exists())->toBeFalse();
});

it('SET NULLs the block link when the assignment is hard-deleted (D-12 FK)', function (): void {
    $campaign = Campaign::factory()->create([
        'posting_window_starts_at' => now()->addDays(5),
        'posting_window_ends_at' => now()->addDays(12),
    ]);
    $assignment = CampaignAssignment::factory()->status(AssignmentStatus::Invited)->create([
        'campaign_id' => $campaign->id,
    ]);

    machine()->accept($assignment);
    $block = CreatorAvailabilityBlock::query()->where('assignment_id', $assignment->id)->firstOrFail();

    // Hard-delete (forceDelete bypasses the SoftDeletes scope) → the FK SET
    // NULLs the block's link rather than cascade-deleting the block.
    $assignment->forceDelete();

    $block->refresh();
    expect($block->exists)->toBeTrue()
        ->and($block->assignment_id)->toBeNull();
});
