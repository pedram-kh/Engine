<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Boards\Enums\MovementTrigger;
use App\Modules\Boards\Models\Board;
use App\Modules\Boards\Models\BoardCard;
use App\Modules\Boards\Models\BoardCardMovement;
use App\Modules\Boards\Services\BoardService;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Events\AssignmentTransitioned;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * @return array{0: Agency, 1: User, 2: Campaign, 3: CampaignAssignment, 4: BoardCard}
 */
function boardWithCard(AssignmentStatus $status = AssignmentStatus::Invited): array
{
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $campaign = Campaign::factory()->create(['agency_id' => $agency->id]);
    $assignment = CampaignAssignment::factory()->create(['campaign_id' => $campaign->id, 'status' => $status]);

    // Lazily provision the board + heal the card (the board-GET path, D-4).
    app(BoardService::class)->forCampaign($campaign);
    $card = BoardCard::query()->where('assignment_id', $assignment->id)->firstOrFail();

    return [$agency, $admin, $campaign, $assignment, $card];
}

function moveUrl(Agency $agency, Campaign $campaign, BoardCard $card): string
{
    return "/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}/board/cards/{$card->ulid}/move";
}

it('records a movement + an audit row on a manual move, with reason', function (): void {
    [$agency, $admin, $campaign, $assignment, $card] = boardWithCard();
    $board = Board::query()->where('campaign_id', $campaign->id)->firstOrFail();
    $paid = $board->columns()->where('name', 'Paid')->firstOrFail();

    $this->actingAs($admin)->postJson(moveUrl($agency, $campaign, $card), [
        'target_column_id' => $paid->ulid,
        'reason' => 'Closing out manually',
    ])->assertOk()->assertJsonPath('data.relationships.column.data.id', $paid->ulid);

    $movement = BoardCardMovement::query()->where('card_id', $card->id)->firstOrFail();
    expect($movement->triggered_by)->toBe(MovementTrigger::User)
        ->and($movement->triggered_by_user_id)->toBe($admin->id)
        ->and($movement->to_column_id)->toBe($paid->id)
        ->and($movement->reason)->toBe('Closing out manually')
        ->and($movement->triggered_event_key)->toBeNull();

    expect(
        AuditLog::query()
            ->where('action', 'board.card_moved_manually')
            ->where('subject_id', $card->id)
            ->count(),
    )->toBe(1);
});

/**
 * THE load-bearing safety test (§4.4 / §5.34 / D-8): a manual move to "Paid" is
 * a VISUALIZATION change only — the assignment status is UNCHANGED and NO
 * assignment transition event is dispatched (no state-machine call).
 */
it('manual move has NO side effect on assignment state (the critical safety invariant)', function (): void {
    [$agency, $admin, $campaign, $assignment, $card] = boardWithCard(AssignmentStatus::Invited);
    $board = Board::query()->where('campaign_id', $campaign->id)->firstOrFail();
    $paid = $board->columns()->where('name', 'Paid')->firstOrFail();

    Event::fake([AssignmentTransitioned::class]);

    $this->actingAs($admin)->postJson(moveUrl($agency, $campaign, $card), [
        'target_column_id' => $paid->ulid,
    ])->assertOk();

    // The card moved (visualization)…
    expect($card->fresh()?->column_id)->toBe($paid->id);
    // …but reality did NOT: status unchanged, no transition event, no payment verbs.
    expect($assignment->fresh()?->status)->toBe(AssignmentStatus::Invited);
    Event::assertNotDispatched(AssignmentTransitioned::class);
    expect(AuditLog::query()->where('subject_id', $assignment->id)->whereIn('action', [
        'assignment.payment_released', 'assignment.payment_funded',
    ])->exists())->toBeFalse();
});

it('lists the card movement history', function (): void {
    [$agency, $admin, $campaign, $assignment, $card] = boardWithCard();
    $board = Board::query()->where('campaign_id', $campaign->id)->firstOrFail();
    $approved = $board->columns()->where('name', 'Approved')->firstOrFail();

    $this->actingAs($admin)->postJson(moveUrl($agency, $campaign, $card), [
        'target_column_id' => $approved->ulid,
    ])->assertOk();

    $this->actingAs($admin)
        ->getJson("/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}/board/cards/{$card->ulid}/movements")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.attributes.triggered_by', 'user')
        ->assertJsonPath('data.0.attributes.to_column_id', $approved->ulid);
});

it('staff CAN move a card (the execute ability)', function (): void {
    [$agency, , $campaign, , $card] = boardWithCard();
    $staff = User::factory()->agencyStaff($agency)->createOne();
    $board = Board::query()->where('campaign_id', $campaign->id)->firstOrFail();
    $approved = $board->columns()->where('name', 'Approved')->firstOrFail();

    $this->actingAs($staff)->postJson(moveUrl($agency, $campaign, $card), [
        'target_column_id' => $approved->ulid,
    ])->assertOk();
});

// ── Tenancy = ABSENCE (404, not 403) ────────────────────────────────────────

it('agency B GETting agency A\'s board → 404 (not 403, no ULID-validity leak)', function (): void {
    [$agencyA, , $campaignA] = boardWithCard();
    $agencyB = Agency::factory()->createOne();
    $adminB = User::factory()->agencyAdmin($agencyB)->createOne();

    // B addresses A's campaign under B's own agency path → 404 (campaign not in B).
    $this->actingAs($adminB)
        ->getJson("/api/v1/agencies/{$agencyB->ulid}/campaigns/{$campaignA->ulid}/board")
        ->assertNotFound();
});

it('agency B moving a card on agency A\'s board → 404', function (): void {
    [$agencyA, , $campaignA, , $cardA] = boardWithCard();
    $boardA = Board::query()->where('campaign_id', $campaignA->id)->firstOrFail();
    $targetA = $boardA->columns()->where('name', 'Approved')->firstOrFail();

    $agencyB = Agency::factory()->createOne();
    $adminB = User::factory()->agencyAdmin($agencyB)->createOne();

    $this->actingAs($adminB)
        ->postJson("/api/v1/agencies/{$agencyB->ulid}/campaigns/{$campaignA->ulid}/board/cards/{$cardA->ulid}/move", [
            'target_column_id' => $targetA->ulid,
        ])
        ->assertNotFound();
});
