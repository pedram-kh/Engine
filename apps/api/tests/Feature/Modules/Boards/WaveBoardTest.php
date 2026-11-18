<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Boards\Exceptions\BoardRuleViolation;
use App\Modules\Boards\Models\Board;
use App\Modules\Boards\Models\BoardCard;
use App\Modules\Boards\Models\BoardCardMovement;
use App\Modules\Boards\Models\BoardColumn;
use App\Modules\Boards\Services\BoardAutomationService;
use App\Modules\Boards\Services\BoardResetService;
use App\Modules\Boards\Services\BoardService;
use App\Modules\Boards\Services\OverdueScanService;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Enums\CampaignApplicationStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignApplication;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\WaveFixtures;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * PL-1.2 sub-step 2 — the for-a-wave board: nine columns (eight rows), fixed shape, two automations, the invite
 * label and the Accepted rule for working columns (Q4). Normal boards are pinned unchanged (C3, P7).
 */

const WB_STAGES = ['invited', 'selected', 'filming', 'submitted', 'in_edits', 'client_review', 'approved', 'dropped'];
const WB_WORKING = ['selected', 'filming', 'submitted', 'in_edits', 'client_review', 'approved'];

/** @return array{agency: Agency, admin: User, manager: User, staff: User, campaign: Campaign, board: Board} */
function wbBoard(bool $forWave = true): array
{
    $agency = Agency::factory()->createOne();
    $campaign = $forWave
        ? Campaign::factory()->forWave()->create(['agency_id' => $agency->id])
        : Campaign::factory()->create(['agency_id' => $agency->id]);
    $board = app(BoardService::class)->ensureBoard($campaign);

    return [
        'agency' => $agency,
        'admin' => User::factory()->agencyAdmin($agency)->createOne(),
        'manager' => User::factory()->agencyManager($agency)->createOne(),
        'staff' => User::factory()->agencyStaff($agency)->createOne(),
        'campaign' => $campaign,
        'board' => $board,
    ];
}

function wbCard(array $w, AssignmentStatus $status = AssignmentStatus::Invited): BoardCard
{
    $assignment = CampaignAssignment::factory()->create(['agency_id' => $w['agency']->id, 'campaign_id' => $w['campaign']->id, 'status' => $status]);
    app(BoardService::class)->forCampaign($w['campaign']);

    return BoardCard::query()->where('assignment_id', $assignment->id)->firstOrFail();
}

function wbStage(Board $board, string $stage): BoardColumn
{
    return $board->columns()->where('stage_key', $stage)->firstOrFail();
}

function wbUrl(array $w, string $suffix = ''): string
{
    return "/api/v1/agencies/{$w['agency']->ulid}/campaigns/{$w['campaign']->ulid}/board".($suffix !== '' ? '/'.$suffix : '');
}

/** @return array{target_column_id: string} */
function wbMoveBody(BoardColumn $column): array
{
    return ['target_column_id' => $column->ulid];
}

it('C3: a normal campaign still gets exactly the seven default columns and ten automations, and its JSON has no wave keys', function (): void {
    $w = wbBoard(forWave: false);
    $card = wbCard($w);

    expect($w['board']->columns()->pluck('name')->all())->toBe(['To Define', 'Invited', 'In Review', 'Approved', 'Posted', 'Paid', 'Cancelled / Rejected'])
        ->and($w['board']->automations()->count())->toBe(10)
        ->and($w['board']->columns()->whereNotNull('stage_key')->count())->toBe(0);

    $json = $this->actingAs($w['admin'])->getJson(wbUrl($w))->assertOk()->json();
    expect(str_contains((string) json_encode($json), 'stage_key'))->toBeFalse()
        ->and(str_contains((string) json_encode($json), 'invite_state'))->toBeFalse();
    expect($card->exists)->toBeTrue();
});

it('P6: a for-a-wave board has the eight data columns in order with stage keys, one failure terminal, and two automations', function (): void {
    $w = wbBoard();

    expect($w['board']->columns()->pluck('name')->all())->toBe(['Invited', 'Selected', 'Filming', 'Submitted / in review', 'In edits', 'Client review', 'Approved', 'Dropped'])
        ->and($w['board']->columns()->pluck('stage_key')->all())->toBe(WB_STAGES)
        ->and($w['board']->columns()->pluck('position')->all())->toBe([1, 2, 3, 4, 5, 6, 7, 8])
        ->and($w['board']->columns()->where('is_terminal_failure', true)->pluck('stage_key')->all())->toBe(['dropped'])
        ->and($w['board']->columns()->where('is_terminal_success', true)->count())->toBe(0)
        ->and($w['board']->automations()->pluck('event_key')->sort()->values()->all())->toBe(['assignment.cancelled', 'assignment.invited']);
});

it('P6: creating a for-a-wave campaign through the API provisions the board in the same request (flag ON)', function (): void {
    WaveFixtures::armed();
    $w = WaveFixtures::world();
    $res = $this->actingAs($w['admin'])->postJson("/api/v1/agencies/{$w['agency']->ulid}/campaigns", [
        'brand_id' => $w['brand']->ulid, 'name' => 'W', 'budget_minor_units' => 0, 'budget_currency' => 'EUR', 'campaign_type' => 'for_wave',
    ])->assertCreated();

    $campaign = Campaign::query()->where('ulid', $res->json('data.id'))->firstOrFail();
    $board = Board::query()->where('campaign_id', $campaign->id)->firstOrFail();
    expect($board->columns()->count())->toBe(8)->and($board->automations()->count())->toBe(2);
});

it('B1: the structure of a wave board is fixed: every structure route is 422 board.wave_columns_fixed and changes nothing', function (): void {
    $w = wbBoard();
    $card = wbCard($w);
    $column = wbStage($w['board'], 'selected');
    $automation = $w['board']->automations()->firstOrFail();
    $before = $w['board']->columns()->pluck('name', 'ulid')->all();
    $ids = $w['board']->columns()->pluck('ulid')->all();

    $calls = [
        $this->actingAs($w['admin'])->postJson(wbUrl($w, 'columns'), ['name' => 'Extra', 'color_token' => 'status-paid']),
        $this->actingAs($w['admin'])->patchJson(wbUrl($w, "columns/{$column->ulid}"), ['name' => 'Renamed']),
        $this->actingAs($w['admin'])->deleteJson(wbUrl($w, "columns/{$column->ulid}"), ['destination_column_id' => wbStage($w['board'], 'invited')->ulid]),
        $this->actingAs($w['admin'])->patchJson(wbUrl($w, 'columns/reorder'), ['column_ids' => array_reverse($ids)]),
        $this->actingAs($w['admin'])->patchJson(wbUrl($w, "automations/{$automation->ulid}"), ['is_enabled' => false]),
        $this->actingAs($w['admin'])->postJson(wbUrl($w, 'reset-to-defaults')),
    ];
    foreach ($calls as $res) {
        $res->assertUnprocessable()->assertJsonPath('errors.0.code', 'board.wave_columns_fixed');
    }

    expect($w['board']->columns()->pluck('name', 'ulid')->all())->toBe($before)
        ->and($w['board']->automations()->where('is_enabled', true)->count())->toBe(2)
        ->and($card->fresh()?->column_id)->toBe(wbStage($w['board'], 'invited')->id);
});

it('B1: the same structure calls on a NORMAL board still work', function (): void {
    $w = wbBoard(forWave: false);

    $this->actingAs($w['admin'])->postJson(wbUrl($w, 'columns'), ['name' => 'Extra', 'color_token' => 'status-paid'])->assertCreated();
    $this->actingAs($w['admin'])->postJson(wbUrl($w, 'reset-to-defaults'))->assertOk();
});

it('B1: BoardResetService::reset called directly throws on a wave board and the nine columns survive', function (): void {
    $w = wbBoard();

    expect(fn () => app(BoardResetService::class)->reset($w['board'], $w['admin']))->toThrow(BoardRuleViolation::class);
    expect($w['board']->columns()->count())->toBe(8);
});

it('B2a: a move into each working column needs an Accepted creator: Invited, Countered, Declined and Cancelled are refused with their own code', function (string $stage): void {
    $w = wbBoard();
    $target = wbStage($w['board'], $stage);

    foreach ([AssignmentStatus::Invited, AssignmentStatus::Countered, AssignmentStatus::Declined, AssignmentStatus::Cancelled] as $status) {
        $card = wbCard($w, $status);
        $from = $card->column_id;
        $this->actingAs($w['admin'])->postJson(wbUrl($w, "cards/{$card->ulid}/move"), wbMoveBody($target))->assertUnprocessable()->assertJsonPath('errors.0.code', 'board.selection_requires_acceptance');

        expect($card->fresh()?->column_id)->toBe($from)
            ->and(BoardCardMovement::query()->where('card_id', $card->id)->count())->toBe(0);
    }

    $accepted = wbCard($w, AssignmentStatus::Accepted);
    $this->actingAs($w['admin'])->postJson(wbUrl($w, "cards/{$accepted->ulid}/move"), wbMoveBody($target))->assertOk();
    expect($accepted->fresh()?->column_id)->toBe($target->id);
})->with(WB_WORKING);

it('B2b: any card can be moved to Dropped and back to Invited, and neither touches the assignment', function (): void {
    $w = wbBoard();
    $dropped = wbStage($w['board'], 'dropped');
    $invited = wbStage($w['board'], 'invited');

    foreach ([AssignmentStatus::Invited, AssignmentStatus::Declined, AssignmentStatus::Accepted] as $status) {
        $card = wbCard($w, $status);
        $this->actingAs($w['admin'])->postJson(wbUrl($w, "cards/{$card->ulid}/move"), wbMoveBody($dropped))->assertOk();
        expect($card->fresh()?->column_id)->toBe($dropped->id);
        $this->actingAs($w['admin'])->postJson(wbUrl($w, "cards/{$card->ulid}/move"), wbMoveBody($invited))->assertOk();
        expect($card->fresh()?->column_id)->toBe($invited->id)
            ->and($card->assignment()->first()?->status)->toBe($status);
    }
});

it('B2b: an Accepted card moves back out of a working column to Invited and between working columns freely', function (): void {
    $w = wbBoard();
    $card = wbCard($w, AssignmentStatus::Accepted);

    foreach (['selected', 'filming', 'approved', 'selected', 'invited'] as $stage) {
        $this->actingAs($w['admin'])->postJson(wbUrl($w, "cards/{$card->ulid}/move"), wbMoveBody(wbStage($w['board'], $stage)))->assertOk();
        expect($card->fresh()?->column_id)->toBe(wbStage($w['board'], $stage)->id);
    }
});

it('B3: staff can move cards but cannot touch structure; another agency gets 404', function (): void {
    $w = wbBoard();
    $card = wbCard($w, AssignmentStatus::Accepted);
    $other = wbBoard();

    $this->actingAs($w['staff'])->postJson(wbUrl($w, "cards/{$card->ulid}/move"), wbMoveBody(wbStage($w['board'], 'selected')))->assertOk();
    $this->actingAs($w['staff'])->postJson(wbUrl($w, 'columns'), ['name' => 'X', 'color_token' => 'status-paid'])->assertForbidden();
    $this->actingAs($other['admin'])->getJson(wbUrl($w))->assertNotFound();
    $this->actingAs($other['admin'])->postJson(wbUrl($w, "cards/{$card->ulid}/move"), wbMoveBody(wbStage($w['board'], 'filming')))->assertNotFound();
});

it('B4: invite_state is invited / accepted / declined on Invited cards, absent for later statuses, and stage_key is on wave columns', function (): void {
    $w = wbBoard();
    $cards = [
        'invited' => wbCard($w, AssignmentStatus::Invited),
        'invited2' => wbCard($w, AssignmentStatus::Countered),
        'accepted' => wbCard($w, AssignmentStatus::Accepted),
        'declined' => wbCard($w, AssignmentStatus::Declined),
        'rejected' => wbCard($w, AssignmentStatus::Rejected),
        'later' => wbCard($w, AssignmentStatus::Cancelled),
    ];

    $json = $this->actingAs($w['admin'])->getJson(wbUrl($w))->assertOk()->json();
    $byId = array_column($json['data']['cards'], null, 'id');
    $state = fn (string $k) => $byId[$cards[$k]->ulid]['invite_state'] ?? 'ABSENT';

    expect($state('invited'))->toBe('invited')
        ->and($state('invited2'))->toBe('invited')
        ->and($state('accepted'))->toBe('accepted')
        ->and($state('declined'))->toBe('declined')
        ->and($state('rejected'))->toBe('ABSENT')
        ->and($state('later'))->toBe('ABSENT')
        ->and(array_map(static fn (array $c): mixed => $c['attributes']['stage_key'] ?? null, $json['data']['columns']))->toBe(WB_STAGES);

    // Declined never moves by itself.
    expect($cards['declined']->fresh()?->column_id)->toBe(wbStage($w['board'], 'invited')->id);
});

it('D3a: every status that counts as selected gives the accepted state, whatever its column, and nothing else does', function (): void {
    $w = wbBoard();
    $cards = [];
    foreach (AssignmentStatus::cases() as $status) {
        $cards[$status->value] = wbCard($w, $status);
    }

    $json = $this->actingAs($w['admin'])->getJson(wbUrl($w))->assertOk()->json();
    $byId = array_column($json['data']['cards'], null, 'id');

    foreach (AssignmentStatus::cases() as $status) {
        $expected = match (true) {
            $status === AssignmentStatus::Invited, $status === AssignmentStatus::Countered => 'invited',
            $status === AssignmentStatus::Declined => 'declined',
            $status->countsAsSelected() => 'accepted',
            default => 'ABSENT',
        };

        expect($byId[$cards[$status->value]->ulid]['invite_state'] ?? 'ABSENT')->toBe($expected, "status {$status->value}");
    }

    // The walk's case: acceptance moved the assignment on to contracted.
    expect($byId[$cards['contracted']->ulid]['invite_state'])->toBe('accepted');
});

it('D3c: the working columns keep what they showed: the status label, no origin, and the colour only for Accepted', function (): void {
    $w = wbBoard();
    $accepted = wbCard($w, AssignmentStatus::Accepted);
    $contracted = wbCard($w, AssignmentStatus::Contracted);
    $selected = wbStage($w['board'], 'selected');
    foreach ([$accepted, $contracted] as $card) {
        $this->actingAs($w['admin'])->postJson(wbUrl($w, "cards/{$card->ulid}/move"), wbMoveBody($selected))->assertOk();
    }

    $json = $this->actingAs($w['admin'])->getJson(wbUrl($w))->assertOk()->json();
    $byId = array_column($json['data']['cards'], null, 'id');

    expect($byId[$accepted->ulid]['invite_state'])->toBe('accepted')
        ->and(array_key_exists('invite_origin', $byId[$accepted->ulid]))->toBeFalse()
        ->and(array_key_exists('invite_state', $byId[$contracted->ulid]))->toBeFalse()
        ->and(array_key_exists('invite_origin', $byId[$contracted->ulid]))->toBeFalse();
});

it('D3b: the origin is application when an application was accepted for that creator, invite otherwise', function (): void {
    $w = wbBoard();
    $applied = wbCard($w, AssignmentStatus::Invited);
    $appliedAccepted = wbCard($w, AssignmentStatus::Contracted);
    $invited = wbCard($w, AssignmentStatus::Invited);
    $invitedAccepted = wbCard($w, AssignmentStatus::Accepted);
    $pending = wbCard($w, AssignmentStatus::Invited);
    $rejected = wbCard($w, AssignmentStatus::Invited);

    $apply = function (BoardCard $card, CampaignApplicationStatus $status) use ($w): void {
        CampaignApplication::factory()->create([
            'agency_id' => $w['agency']->id, 'campaign_id' => $w['campaign']->id,
            'creator_id' => $card->assignment?->creator_id, 'status' => $status,
        ]);
    };
    $apply($applied, CampaignApplicationStatus::Accepted);
    $apply($appliedAccepted, CampaignApplicationStatus::Accepted);
    $apply($pending, CampaignApplicationStatus::Pending);
    $apply($rejected, CampaignApplicationStatus::Rejected);
    // An accepted application on ANOTHER campaign says nothing about this one.
    CampaignApplication::factory()->create(['status' => CampaignApplicationStatus::Accepted, 'creator_id' => $invited->assignment?->creator_id]);

    $json = $this->actingAs($w['admin'])->getJson(wbUrl($w))->assertOk()->json();
    $byId = array_column($json['data']['cards'], null, 'id');
    $pair = fn (BoardCard $c): string => ($byId[$c->ulid]['invite_state'] ?? '-').'/'.($byId[$c->ulid]['invite_origin'] ?? '-');

    expect($pair($applied))->toBe('invited/application')
        ->and($pair($appliedAccepted))->toBe('accepted/application')
        ->and($pair($invited))->toBe('invited/invite')
        ->and($pair($invitedAccepted))->toBe('accepted/invite')
        ->and($pair($pending))->toBe('invited/invite')
        ->and($pair($rejected))->toBe('invited/invite');

    // The move response is the same card face, origin included.
    $moved = $this->actingAs($w['admin'])->postJson(wbUrl($w, "cards/{$appliedAccepted->ulid}/move"), wbMoveBody(wbStage($w['board'], 'invited')))->assertOk()->json('data');
    expect($moved['invite_state'])->toBe('accepted')->and($moved['invite_origin'])->toBe('application');
});

it('B5: an invite creates the card in Invited, a cancel lands it in Dropped, and no other event moves a card', function (): void {
    $w = wbBoard();
    $card = wbCard($w, AssignmentStatus::Accepted);
    $invited = wbStage($w['board'], 'invited');
    $assignmentId = $card->assignment_id;
    $service = app(BoardAutomationService::class);

    foreach ([AuditAction::AssignmentDraftSubmitted, AuditAction::AssignmentDraftApproved, AuditAction::AssignmentPostedByCreator, AuditAction::AssignmentPaymentReleased, AuditAction::AssignmentDraftRejected, AuditAction::AssignmentPostingOverdue, AuditAction::AssignmentDraftOverdue] as $event) {
        $service->processEvent($assignmentId, $event->value, [], null);
        expect($card->fresh()?->column_id)->toBe($invited->id);
    }

    $service->processEvent($assignmentId, AuditAction::AssignmentCancelled->value, [], null);
    expect($card->fresh()?->column_id)->toBe(wbStage($w['board'], 'dropped')->id);
});

it('B5: the overdue scan leaves a wave-board card where it is, and the lazy heal places a later status in Invited', function (): void {
    $w = wbBoard();
    $assignment = CampaignAssignment::factory()->create([
        'agency_id' => $w['agency']->id, 'campaign_id' => $w['campaign']->id, 'status' => AssignmentStatus::Accepted,
        'draft_due_at' => now()->subDay(), 'posting_due_at' => now()->subDay(),
    ]);
    app(BoardService::class)->forCampaign($w['campaign']);
    $card = BoardCard::query()->where('assignment_id', $assignment->id)->firstOrFail();

    app(OverdueScanService::class)->scan();
    expect($card->fresh()?->column_id)->toBe(wbStage($w['board'], 'invited')->id);

    // Healing: a later-status assignment with no card is placed in Invited (no automation maps its event).
    $late = CampaignAssignment::factory()->create(['agency_id' => $w['agency']->id, 'campaign_id' => $w['campaign']->id, 'status' => AssignmentStatus::Producing]);
    app(BoardService::class)->forCampaign($w['campaign']);
    expect(BoardCard::query()->where('assignment_id', $late->id)->firstOrFail()->column_id)->toBe(wbStage($w['board'], 'invited')->id);
});
