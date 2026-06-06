<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Boards\Models\Board;
use App\Modules\Boards\Models\BoardCard;
use App\Modules\Boards\Services\BoardCardService;
use App\Modules\Boards\Services\BoardService;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Events\AssignmentTransitioned;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * The Event::fake split (§5.2 / team standard 5.2): this half asserts the
 * listener CONSEQUENCE — the board card row — fires when the event is NOT faked.
 * The producer half (CampaignAssignmentController::store dispatches
 * AssignmentTransitioned with AuditAction::AssignmentInvited) is covered by the
 * existing Campaigns invite suite.
 */
it('provisions a board + card when an assignment is invited (real listener fires)', function (): void {
    $assignment = CampaignAssignment::factory()->create(['status' => AssignmentStatus::Invited]);

    AssignmentTransitioned::dispatch(
        $assignment,
        AssignmentStatus::Invited,
        AssignmentStatus::Invited,
        AuditAction::AssignmentInvited,
        null,
    );

    $card = BoardCard::query()->where('assignment_id', $assignment->id)->first();

    expect($card)->not->toBeNull()
        ->and($card?->agency_id)->toBe($assignment->agency_id)
        ->and(Board::query()->where('campaign_id', $assignment->campaign_id)->exists())->toBeTrue();
});

it('does not provision a card on a non-invite transition', function (): void {
    $assignment = CampaignAssignment::factory()->create();

    AssignmentTransitioned::dispatch(
        $assignment,
        AssignmentStatus::Invited,
        AssignmentStatus::Accepted,
        AuditAction::AssignmentAccepted,
        null,
    );

    expect(BoardCard::query()->where('assignment_id', $assignment->id)->exists())->toBeFalse();
});

it('card provisioning is idempotent — repeated calls return the one canonical row', function (): void {
    $campaign = Campaign::factory()->create();
    $assignment = CampaignAssignment::factory()->create(['campaign_id' => $campaign->id]);
    $board = app(BoardService::class)->ensureBoard($campaign);
    $service = app(BoardCardService::class);

    $first = $service->forAssignment($board, $assignment);
    $second = $service->forAssignment($board, $assignment);

    expect($second->id)->toBe($first->id)
        ->and(BoardCard::query()->where('assignment_id', $assignment->id)->count())->toBe(1);
});
