<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Boards\Enums\BoardAutomationActionType;
use App\Modules\Boards\Models\Board;
use App\Modules\Boards\Services\BoardProvisioningService;
use App\Modules\Boards\Support\BoardDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('seeds the 7 default columns in order with the §3.1 tokens + terminals', function (): void {
    $board = Board::factory()->create();

    app(BoardProvisioningService::class)->provisionDefaults($board);

    $columns = $board->columns()->get();

    expect($columns)->toHaveCount(7)
        ->and($columns->pluck('name')->all())->toBe([
            'To Define', 'Invited', 'In Review', 'Approved', 'Posted', 'Paid', 'Cancelled / Rejected',
        ])
        ->and($columns->pluck('color_token')->all())->toBe([
            'status-todefine', 'status-progress', 'status-review',
            'status-aligned', 'status-posted', 'status-paid', 'status-blocked',
        ])
        ->and($columns->pluck('position')->all())->toBe([1, 2, 3, 4, 5, 6, 7]);

    $paid = $columns->firstWhere('name', 'Paid');
    $cancelled = $columns->firstWhere('name', 'Cancelled / Rejected');
    expect($paid?->is_terminal_success)->toBeTrue()
        ->and($paid?->is_terminal_failure)->toBeFalse()
        ->and($cancelled?->is_terminal_failure)->toBeTrue()
        ->and($cancelled?->is_terminal_success)->toBeFalse();

    // Every seeded column carries the board's denormalized agency_id (D-2).
    expect($columns->pluck('agency_id')->unique()->all())->toBe([$board->agency_id]);
});

it('seeds the 10 default automations mapped to the right columns (§3.2 + draft-reject)', function (): void {
    $board = Board::factory()->create();

    app(BoardProvisioningService::class)->provisionDefaults($board);

    $automations = $board->automations()->with('targetColumn')->get();
    expect($automations)->toHaveCount(10);

    $byKey = $automations->keyBy('event_key');

    $expected = [
        'assignment.invited' => 'Invited',
        'assignment.draft_submitted' => 'In Review',
        'assignment.draft_approved' => 'Approved',
        'assignment.posted_by_creator' => 'Posted',
        'assignment.live_verified' => 'Posted',
        'assignment.manually_verified' => 'Posted',
        'assignment.resubmit_requested' => 'Approved',
        'assignment.payment_released' => 'Paid',
        'assignment.cancelled' => 'Cancelled / Rejected',
        'assignment.draft_rejected' => 'Cancelled / Rejected',
    ];

    foreach ($expected as $eventKey => $columnName) {
        $automation = $byKey->get($eventKey);
        expect($byKey->has($eventKey))->toBeTrue("missing automation {$eventKey}")
            ->and($automation?->action_type)->toBe(BoardAutomationActionType::MoveToColumn)
            ->and($automation?->is_enabled)->toBeTrue()
            ->and($automation?->targetColumn?->name)->toBe($columnName);
    }
});

it('is idempotent — a second provision is a no-op (no duplicate columns / automations)', function (): void {
    $board = Board::factory()->create();
    $service = app(BoardProvisioningService::class);

    $service->provisionDefaults($board);
    $service->provisionDefaults($board);

    expect($board->columns()->count())->toBe(7)
        ->and($board->automations()->count())->toBe(10);
});

it('never clobbers an agency-renamed column on re-provision', function (): void {
    $board = Board::factory()->create();
    $service = app(BoardProvisioningService::class);

    $service->provisionDefaults($board);
    $board->columns()->where('name', 'To Define')->update(['name' => 'Backlog']);

    $service->provisionDefaults($board);

    expect($board->columns()->count())->toBe(7)
        ->and($board->columns()->where('name', 'Backlog')->exists())->toBeTrue()
        ->and($board->columns()->where('name', 'To Define')->exists())->toBeFalse();
});

it('every default automation event key is a live AuditAction value (catalog-to-code, Seam 4)', function (): void {
    foreach (BoardDefaults::automations() as $automation) {
        expect(AuditAction::tryFrom($automation['event_key']))
            ->not->toBeNull("default automation {$automation['event_key']} is not a live AuditAction");
    }
});

it('the backfill migration renames a legacy Cancelled column + adds the draft-rejected automation', function (): void {
    $board = Board::factory()->create();
    app(BoardProvisioningService::class)->provisionDefaults($board);

    // Simulate a pre-change board: the old default name, no draft-rejected automation.
    $board->columns()->where('is_terminal_failure', true)->update(['name' => 'Cancelled']);
    $board->automations()->where('event_key', 'assignment.draft_rejected')->delete();

    $migration = require database_path('migrations/2026_07_13_110000_backfill_cancelled_rejected_board_column.php');
    $migration->up();

    $failure = $board->columns()->where('is_terminal_failure', true)->firstOrFail();
    $automation = $board->automations()->where('event_key', 'assignment.draft_rejected')->firstOrFail();

    expect($failure->name)->toBe('Cancelled / Rejected')
        ->and($automation->target_column_id)->toBe($failure->id)
        ->and($automation->is_enabled)->toBeTrue();

    // Idempotent: a second run adds nothing.
    $migration->up();
    expect($board->automations()->where('event_key', 'assignment.draft_rejected')->count())->toBe(1);
});

it('the backfill migration leaves an agency-renamed failure column untouched', function (): void {
    $board = Board::factory()->create();
    app(BoardProvisioningService::class)->provisionDefaults($board);

    $board->columns()->where('is_terminal_failure', true)->update(['name' => 'Dead deals']);
    $board->automations()->where('event_key', 'assignment.draft_rejected')->delete();

    $migration = require database_path('migrations/2026_07_13_110000_backfill_cancelled_rejected_board_column.php');
    $migration->up();

    $failure = $board->columns()->where('is_terminal_failure', true)->firstOrFail();

    // The custom name survives; the automation still lands on the failure column.
    expect($failure->name)->toBe('Dead deals')
        ->and($board->automations()->where('event_key', 'assignment.draft_rejected')->value('target_column_id'))
        ->toBe($failure->id);
});
