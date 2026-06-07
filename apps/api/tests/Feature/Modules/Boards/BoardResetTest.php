<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Boards\Enums\MovementTrigger;
use App\Modules\Boards\Models\Board;
use App\Modules\Boards\Models\BoardCard;
use App\Modules\Boards\Models\BoardCardMovement;
use App\Modules\Boards\Services\BoardCardMoveService;
use App\Modules\Boards\Services\BoardColumnService;
use App\Modules\Boards\Services\BoardResetService;
use App\Modules\Boards\Services\BoardService;
use App\Modules\Boards\Support\BoardDefaults;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Sprint 12 Chunk 3 (D-7/D-8/D-9) — reset-to-defaults, the destructive re-seed.
 * One DB::transaction, Option-A ordering (seed columns → swap automations →
 * re-home → delete old columns → one board.reset audit row). The two
 * load-bearing specs: (1) atomicity — a mid-transaction failure rolls back to
 * the ORIGINAL custom board; (2) placement — every card lands on a FRESH default
 * column (none orphaned on an about-to-be-deleted old column).
 *
 * @return array{agency: Agency, admin: User, campaign: Campaign, board: Board, invited: CampaignAssignment, review: CampaignAssignment, approved: CampaignAssignment}
 */
function customizedBoard(): array
{
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $campaign = Campaign::factory()->create(['agency_id' => $agency->id]);

    $invited = CampaignAssignment::factory()->create(['agency_id' => $agency->id, 'campaign_id' => $campaign->id, 'status' => AssignmentStatus::Invited]);
    $review = CampaignAssignment::factory()->create(['agency_id' => $agency->id, 'campaign_id' => $campaign->id, 'status' => AssignmentStatus::DraftSubmitted]);
    $approved = CampaignAssignment::factory()->create(['agency_id' => $agency->id, 'campaign_id' => $campaign->id, 'status' => AssignmentStatus::Approved]);

    // Lazily provision the board + heal a card for each assignment (D-4).
    app(BoardService::class)->forCampaign($campaign);
    $board = Board::query()->where('campaign_id', $campaign->id)->firstOrFail();

    // Customize: add a custom column + manually move the invited card there (a
    // real movement row, so we can prove history survives the reset).
    $negotiating = app(BoardColumnService::class)->create($board, 'Negotiating', 'status-progress');
    $invitedCard = BoardCard::query()->where('assignment_id', $invited->id)->firstOrFail();
    app(BoardCardMoveService::class)->move($invitedCard, $negotiating, $admin, null);

    return compact('agency', 'admin', 'campaign', 'board', 'invited', 'review', 'approved');
}

function resetUrl(Agency $agency, Campaign $campaign): string
{
    return "/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}/board/reset-to-defaults";
}

/** Expected fresh default column for a card, by assignment state (§6.1). */
function expectedColumnName(CampaignAssignment $assignment): string
{
    return match ($assignment->status) {
        AssignmentStatus::Invited => 'Invited',
        AssignmentStatus::DraftSubmitted => 'In Review',
        AssignmentStatus::Approved => 'Approved',
        default => 'Invited',
    };
}

// ── The destructive re-seed (D-7/D-8/D-9) ───────────────────────────────────

it('restores the 7 default columns, dropping the custom set', function (): void {
    ['admin' => $admin, 'board' => $board] = customizedBoard();

    app(BoardResetService::class)->reset($board, $admin);

    $names = $board->refresh()->columns()->pluck('name')->sort()->values()->all();
    $defaults = collect(BoardDefaults::columns())->pluck('name')->sort()->values()->all();

    expect($names)->toBe($defaults)
        ->and($board->columns()->where('name', 'Negotiating')->exists())->toBeFalse()
        ->and($board->columns()->count())->toBe(7);
});

it('re-homes every card onto a FRESH default column by current state (none orphaned on an old column)', function (): void {
    ['admin' => $admin, 'board' => $board, 'invited' => $invited, 'review' => $review, 'approved' => $approved] = customizedBoard();

    $oldColumnIds = $board->columns()->pluck('id')->all();

    app(BoardResetService::class)->reset($board, $admin);

    $freshColumnIds = $board->refresh()->columns()->pluck('id')->all();

    foreach ([$invited, $review, $approved] as $assignment) {
        $card = BoardCard::query()->where('assignment_id', $assignment->id)->with('column')->firstOrFail();

        // ⚠ Assert against the FRESH set specifically — the Seam-A bug (re-home
        // before swapping automations) would land cards on about-to-be-deleted
        // OLD columns; a naive happy-path check would miss it.
        expect($freshColumnIds)->toContain($card->column_id)
            ->and($oldColumnIds)->not->toContain($card->column_id)
            ->and($card->column?->name)->toBe(expectedColumnName($assignment));
    }
});

it('seeds fresh default automations that point at the fresh columns (never dangling, §14.4)', function (): void {
    ['admin' => $admin, 'board' => $board] = customizedBoard();

    app(BoardResetService::class)->reset($board, $admin);

    $freshColumnIds = $board->refresh()->columns()->pluck('id')->all();
    $automations = $board->automations()->get();

    expect($automations)->toHaveCount(count(BoardDefaults::automations()));
    foreach ($automations as $automation) {
        expect($automation->target_column_id)->not->toBeNull()
            ->and($freshColumnIds)->toContain($automation->target_column_id);
    }
});

it('writes exactly ONE board.reset audit row and NO per-card movement rows (D-8)', function (): void {
    ['admin' => $admin, 'board' => $board] = customizedBoard();

    // Baseline: the setup's single manual move recorded one movement row.
    expect(BoardCardMovement::query()->count())->toBe(1);

    app(BoardResetService::class)->reset($board, $admin);

    expect(AuditLog::query()->where('action', 'board.reset')->count())->toBe(1)
        // The bulk re-home writes NO movement rows — only the pre-existing manual
        // move remains (routing ~N cards through the manual path would fabricate
        // N fake movements).
        ->and(BoardCardMovement::query()->count())->toBe(1);
});

it('preserves movement history across the reset — the trail survives via SET NULL (D-8)', function (): void {
    ['admin' => $admin, 'board' => $board] = customizedBoard();

    $movement = BoardCardMovement::query()->where('triggered_by', MovementTrigger::User)->firstOrFail();

    app(BoardResetService::class)->reset($board, $admin);

    // The row survives the column deletes; its column refs are SET NULL (both the
    // custom "Negotiating" target and the original "Invited" source are gone).
    $movement->refresh();
    expect($movement->exists)->toBeTrue()
        ->and($movement->from_column_id)->toBeNull()
        ->and($movement->to_column_id)->toBeNull();
});

// ── ⚠ Atomicity (D-7) — the riskiest, break-revert anchor ────────────────────

it('rolls back to the ORIGINAL custom board on a mid-transaction failure (atomic, not a half-reset)', function (): void {
    ['admin' => $admin, 'board' => $board] = customizedBoard();

    $originalColumnIds = $board->columns()->pluck('id')->sort()->values()->all();
    $originalNames = $board->columns()->pluck('name')->sort()->values()->all();

    // Force a failure at the LAST step (the audit write). The whole transaction —
    // seed fresh columns, swap automations, re-home cards, delete old columns —
    // must roll back to the original custom board (NOT a both-old-and-new state).
    // (AuditLogger is final, so swap a generic throwing instance rather than
    // mock the concrete class.)
    $throwingAudit = Mockery::mock();
    $throwingAudit->shouldReceive('log')->andThrow(new RuntimeException('forced mid-transaction failure'));
    Audit::swap($throwingAudit);

    expect(fn () => app(BoardResetService::class)->reset($board, $admin))
        ->toThrow(RuntimeException::class);

    $board->refresh();
    expect($board->columns()->pluck('id')->sort()->values()->all())->toBe($originalColumnIds)
        ->and($board->columns()->pluck('name')->sort()->values()->all())->toBe($originalNames)
        ->and($board->columns()->where('name', 'Negotiating')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'board.reset')->count())->toBe(0);
});

// ── The endpoint (route + gate + tenancy) ────────────────────────────────────

it('lets an admin reset the board via the endpoint (200, defaults restored)', function (): void {
    ['agency' => $agency, 'admin' => $admin, 'campaign' => $campaign, 'board' => $board] = customizedBoard();

    $this->actingAs($admin)
        ->postJson(resetUrl($agency, $campaign))
        ->assertOk();

    expect($board->refresh()->columns()->where('name', 'Negotiating')->exists())->toBeFalse()
        ->and($board->columns()->count())->toBe(7);
});

it('forbids STAFF from resetting (update-gated board configuration, not the execute ability)', function (): void {
    ['agency' => $agency, 'campaign' => $campaign] = customizedBoard();
    $staff = User::factory()->agencyStaff($agency)->createOne();

    $this->actingAs($staff)
        ->postJson(resetUrl($agency, $campaign))
        ->assertForbidden();
});

it("404s when agency B resets agency A's board (tenancy absence)", function (): void {
    ['campaign' => $campaignA] = customizedBoard();
    $agencyB = Agency::factory()->createOne();
    $adminB = User::factory()->agencyAdmin($agencyB)->createOne();

    $this->actingAs($adminB)
        ->postJson("/api/v1/agencies/{$agencyB->ulid}/campaigns/{$campaignA->ulid}/board/reset-to-defaults")
        ->assertNotFound();
});
