<?php

declare(strict_types=1);

use App\Modules\Boards\Models\Board;
use App\Modules\Boards\Models\BoardCard;
use App\Modules\Boards\Services\BoardService;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('first forCampaign creates the board + default columns + automations (D-4)', function (): void {
    $campaign = Campaign::factory()->create();

    $board = app(BoardService::class)->forCampaign($campaign);

    expect($board->campaign_id)->toBe($campaign->id)
        ->and($board->agency_id)->toBe($campaign->agency_id)
        ->and($board->columns()->count())->toBe(7)
        ->and($board->automations()->count())->toBe(9)
        ->and(Board::query()->where('campaign_id', $campaign->id)->count())->toBe(1);
});

it('heals a card for every card-less assignment (no backfill migration)', function (): void {
    $campaign = Campaign::factory()->create();
    $invited = CampaignAssignment::factory()->create(['campaign_id' => $campaign->id, 'status' => AssignmentStatus::Invited]);
    $posted = CampaignAssignment::factory()->create(['campaign_id' => $campaign->id, 'status' => AssignmentStatus::Posted]);

    expect(BoardCard::query()->count())->toBe(0);

    $board = app(BoardService::class)->forCampaign($campaign);

    expect($board->cards()->count())->toBe(2);

    // §6.1 placement: the invited card lands in the invited-automation target
    // (Invited); the posted card lands in the posted-automation target (Posted).
    $invitedCard = BoardCard::query()->where('assignment_id', $invited->id)->firstOrFail()->load('column');
    $postedCard = BoardCard::query()->where('assignment_id', $posted->id)->firstOrFail()->load('column');

    expect($invitedCard->column?->name)->toBe('Invited')
        ->and($postedCard->column?->name)->toBe('Posted');
});

it('is idempotent — a second forCampaign creates no new rows', function (): void {
    $campaign = Campaign::factory()->create();
    CampaignAssignment::factory()->count(2)->create(['campaign_id' => $campaign->id]);
    $service = app(BoardService::class);

    $service->forCampaign($campaign);
    $service->forCampaign($campaign);

    $board = Board::query()->where('campaign_id', $campaign->id)->firstOrFail();
    expect(Board::query()->where('campaign_id', $campaign->id)->count())->toBe(1)
        ->and($board->columns()->count())->toBe(7)
        ->and($board->automations()->count())->toBe(9)
        ->and($board->cards()->count())->toBe(2);
});
