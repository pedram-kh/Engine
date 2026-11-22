<?php

declare(strict_types=1);

use App\Core\Tenancy\TenancyContext;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Boards\Enums\MovementTrigger;
use App\Modules\Boards\Models\BoardCardMovement;
use App\Modules\Boards\Models\BoardColumn;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Enums\CampaignApplicationStatus;
use App\Modules\Campaigns\Events\AssignmentTransitioned;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Campaigns\Services\CampaignAssignmentStateMachine;
use App\Modules\Strategy\Features\CreatorsScriptsEnabled;
use App\Modules\Strategy\Listeners\ReconcileWaveCreatorOnAccept;
use App\Modules\Strategy\Models\WaveCreator;
use App\Modules\Strategy\Services\WaveCreatorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\CreatorFixtures as F;
use Tests\Support\SentryRecording;
use Tests\Support\StrategyFixtures as S;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * PL-3.1 — the derived status table, the board rules (plan table rows), and the production listener's six conditions.
 */

/**
 * @param  array<string, mixed>  $w
 * @return TestResponse<Response>
 */
function decide(array $w, WaveCreator $slot, string $status = 'approved'): TestResponse
{
    $body = $status === 'approved' ? ['status' => 'approved', 'client_name' => 'Anna'] : ['status' => 'suggest_another', 'client_name' => 'Anna', 'comment' => 'someone else'];

    return S::call($w['admin'], 'POST', F::url($w, "{$slot->ulid}/decisions"), $body);
}

function stageOf(CampaignAssignment $a): string
{
    return (string) F::card($a)->column?->stage_key;
}

function putInStage(CampaignAssignment $a, string $stage): void
{
    $card = F::card($a);
    $card->forceFill(['column_id' => BoardColumn::query()->withoutGlobalScopes()->where('board_id', $card->board_id)->where('stage_key', $stage)->value('id')])->save();
}

function accept(CampaignAssignment $a): void
{
    app(CampaignAssignmentStateMachine::class)->accept($a->refresh());
}

/** @return array{0: array<string, mixed>, 1: CampaignAssignment, 2: WaveCreator} */
function invitedSlot(): array
{
    $w = F::world();
    $c = F::invitee($w['campaign']);
    $a = CampaignAssignment::query()->withoutGlobalScopes()->where('creator_id', $c->id)->firstOrFail();

    return [$w, $a, F::slot($w, $c)];
}

it('ST1: the status table has a row for EVERY assignment status and EVERY application status', function (): void {
    expect(array_keys(WaveCreatorStatus::ASSIGNMENT))->toEqualCanonicalizing(array_map(static fn ($c) => $c->value, AssignmentStatus::cases()))
        ->and(array_keys(WaveCreatorStatus::APPLICATION))->toEqualCanonicalizing(array_map(static fn ($c) => $c->value, CampaignApplicationStatus::cases()))
        ->and(array_unique(array_column(WaveCreatorStatus::ASSIGNMENT + WaveCreatorStatus::APPLICATION, 0)))->each->toBeIn(WaveCreatorStatus::ALL);
});

it('BR1: approved first, accepted later: the accept moves the card Invited to Selected (row 3)', function (): void {
    [$w, $a, $slot] = invitedSlot();

    decide($w, $slot)->assertCreated()->assertJsonPath('meta.card_moved', false);
    expect(stageOf($a))->toBe('invited');

    accept($a);
    expect(stageOf($a))->toBe('selected');
});

it('BR2: accepted first, approved later: the decision moves the card to Selected (row 7)', function (): void {
    [$w, $a, $slot] = invitedSlot();
    accept($a);
    $before = stageOf($a);

    decide($w, $slot)->assertCreated()->assertJsonPath('meta.card_moved', true);
    expect(stageOf($a))->toBe('selected')->and($before)->not->toBe('selected');
});

it('BR3: Suggest another sends a Selected card back to Invited (row 8) and an accept over it does nothing', function (): void {
    [$w, $a, $slot] = invitedSlot();
    accept($a);
    decide($w, $slot)->assertCreated();
    decide($w, $slot, 'suggest_another')->assertCreated()->assertJsonPath('meta.card_moved', true);
    expect(stageOf($a))->toBe('invited');

    $assignment = $a->refresh();
    event(new AssignmentTransitioned($assignment, AssignmentStatus::Invited, AssignmentStatus::Accepted, AuditAction::AssignmentAccepted));
    expect(stageOf($a))->toBe('invited');

    // Events, not a schedule: a card the agency put in Selected by hand stays there when an accept event arrives.
    putInStage($a, 'selected');
    event(new AssignmentTransitioned($assignment, AssignmentStatus::Invited, AssignmentStatus::Accepted, AuditAction::AssignmentAccepted));
    expect(stageOf($a))->toBe('selected');
});

it('BR4: Filming or later: the card is NOT moved by a decision, which says so (beyond_selected)', function (): void {
    [$w, $a, $slot] = invitedSlot();
    accept($a);
    putInStage($a, 'filming');

    decide($w, $slot, 'suggest_another')->assertCreated()->assertJsonPath('meta.card_moved', false)->assertJsonPath('meta.card_not_moved', 'beyond_selected');
    expect(stageOf($a))->toBe('filming');
    decide($w, $slot)->assertCreated();
    expect(stageOf($a))->toBe('filming');
});

it('BR5: Remove from Selected sends the card back to Invited; from Filming or later it is refused and nothing changes', function (): void {
    [$w, $a, $slot] = invitedSlot();
    accept($a);
    decide($w, $slot)->assertCreated();
    expect(stageOf($a))->toBe('selected');

    putInStage($a, 'filming');
    S::call($w['admin'], 'DELETE', F::url($w, $slot->ulid))->assertStatus(422)->assertJsonPath('errors.0.code', 'wave_creator.remove_blocked_in_work');
    S::call($w['admin'], 'POST', F::url($w, "{$slot->ulid}/replace"), ['creator' => F::applicant($w['campaign'])->ulid])->assertStatus(422);
    expect(WaveCreator::query()->whereKey($slot->id)->exists())->toBeTrue()->and(stageOf($a))->toBe('filming');

    putInStage($a, 'selected');
    S::call($w['admin'], 'DELETE', F::url($w, $slot->ulid))->assertOk();
    expect(stageOf($a))->toBe('invited')->and(WaveCreator::query()->whereKey($slot->id)->exists())->toBeFalse();
});

it('BR6: a rule move writes ONE movement row (triggered by an event, no user text) and one card_moved audit row with ids only', function (): void {
    [$w, $a, $slot] = invitedSlot();
    accept($a);
    $moves = BoardCardMovement::query()->count();
    decide($w, $slot)->assertCreated();

    $row = BoardCardMovement::query()->latest('id')->firstOrFail();
    $audit = F::lastAudit('wave_creator.card_moved');
    expect(BoardCardMovement::query()->count())->toBe($moves + 1)
        ->and($row->triggered_by)->toBe(MovementTrigger::Event)
        ->and($row->triggered_event_key)->toBe('wave_creator.selected')
        ->and(json_encode($audit->metadata))->not->toContain('Anna')
        ->and(F::meta('wave_creator.card_moved', 'trigger'))->toBe('decision');
});

it('L1: flags off: the listener runs ZERO queries, for the accept event and for any other', function (): void {
    [$w, $a] = invitedSlot();
    $queries = [];
    foreach ([[], ['strategy_enabled'], ['waves_enabled']] as $off) {
        Feature::activate('strategy_enabled');
        Feature::activate('waves_enabled');
        Feature::activate(CreatorsScriptsEnabled::NAME);
        if ($off === []) {
            Feature::deactivate(CreatorsScriptsEnabled::NAME);
        }
        foreach ($off as $f) {
            Feature::deactivate($f);
        }
        $event = new AssignmentTransitioned($a->refresh(), AssignmentStatus::Invited, AssignmentStatus::Accepted, AuditAction::AssignmentAccepted);
        DB::flushQueryLog();
        DB::enableQueryLog();
        (new ReconcileWaveCreatorOnAccept)->handle($event);
        $queries[] = count(DB::getQueryLog());
        DB::disableQueryLog();
    }
    expect($queries)->toBe([0, 0, 0]);
});

it('L2: a normal campaign: with all three flags on, the accept writes exactly the statements it writes with them off, and the listener adds ONE read', function (): void {
    $w = F::world(linked: false);
    $run = static function () use ($w): array {
        $c = F::invitee($w['campaign']);
        $a = CampaignAssignment::query()->withoutGlobalScopes()->where('creator_id', $c->id)->firstOrFail();
        $log = [];
        DB::listen(static function ($q) use (&$log): void {
            $log[] = $q->sql;
        });
        $result = app(CampaignAssignmentStateMachine::class)->accept($a);

        return [$log, $result->status, DB::table('board_cards')->where('assignment_id', $a->id)->value('column_id') !== null];
    };

    $sentry = SentryRecording::install();
    Feature::deactivate(CreatorsScriptsEnabled::NAME);
    [$off, $statusOff] = $run();
    Feature::activate(CreatorsScriptsEnabled::NAME);
    [$on, $statusOn] = $run();

    $writes = static fn (array $log): array => array_values(array_filter($log, static fn ($q) => preg_match('/^\s*(insert|update|delete)/i', $q) === 1));
    $own = static fn ($q) => str_starts_with($q, 'select "id" from "waves" where "campaign_id"');
    $sentry->flush();
    SentryRecording::reset();
    expect($sentry->bodies)->toBe([])->and($statusOn)->toBe($statusOff)->and($writes($on))->toBe($writes($off))
        ->and(count(array_filter($on, $own)))->toBe(1)->and(count(array_filter($off, $own)))->toBe(0)
        ->and(WaveCreator::query()->count())->toBe(0)->and(DB::table('wave_creator_decisions')->count())->toBe(0);
});

it('L3: an exception inside the listener leaves the accept committed and is reported with ids only', function (): void {
    [$w, $a, $slot] = invitedSlot();
    decide($w, $slot)->assertCreated();
    $sentry = SentryRecording::install();

    try {
        // Break the deferred work: the lookup it runs under the wave lock fails.
        DB::statement('ALTER TABLE wave_creators RENAME TO wave_creators_gone');
        accept($a);
        DB::statement('ALTER TABLE wave_creators_gone RENAME TO wave_creators');
        $sentry->flush();

        expect(CampaignAssignment::query()->withoutGlobalScopes()->whereKey($a->id)->value('status'))->toBe(AssignmentStatus::Accepted)
            ->and($sentry->bodies)->not->toBeEmpty();
        $body = implode("\n", $sentry->bodies);
        expect($body)->toContain('wave creator reconcile on accept failed')->and($body)->toContain("assignment_id={$a->id}")
            ->and($body)->not->toContain('Anna');
    } finally {
        SentryRecording::reset();
    }
});

it('L4: the work runs only AFTER the accept transaction commits, inside the accept never', function (): void {
    [$w, $a, $slot] = invitedSlot();
    decide($w, $slot)->assertCreated();
    $a->refresh();

    $seen = null;
    DB::listen(static function ($q) use (&$seen): void {
        if ($seen === null && str_contains($q->sql, 'update "board_cards"')) {
            $seen = DB::transactionLevel();
        }
    });
    DB::beginTransaction();
    try {
        app(CampaignAssignmentStateMachine::class)->accept($a);
        // Still inside the caller's own transaction: nothing may have moved the card yet.
        expect(stageOf($a))->not->toBe('selected');
    } finally {
        DB::commit();
    }
    expect(stageOf($a))->toBe('selected');
});

it('L5: the listener needs no agency tenancy context (the creator accepts on their own request)', function (): void {
    [$w, $a, $slot] = invitedSlot();
    decide($w, $slot)->assertCreated();
    app()->forgetInstance('tenancy.agency');
    if (class_exists(TenancyContext::class)) {
        app(TenancyContext::class)->forget();
    }

    accept($a);
    expect(stageOf($a))->toBe('selected');
});

it('B37: every write to wave_creators and wave_creator_decisions in the Strategy module is inside WaveCreatorGuard::transaction (source check)', function (): void {
    $allowed = ['WaveCreatorService.php', 'WaveCreatorDecisionService.php'];
    $writers = [];
    foreach (glob(app_path('Modules/Strategy/*/*.php')) ?: [] as $file) {
        $src = (string) file_get_contents($file);
        if (preg_match('/WaveCreator(Decision)?::(query\(\)->)?(create|insert|upsert|updateOrCreate)|WaveCreator(Decision)?::query\(\)->[^;]*->(delete|update)\(/', $src) === 1) {
            $writers[] = basename($file);
            // A file that writes must open the wave-locked transaction, once per public writer.
            expect(substr_count($src, 'WaveCreatorGuard::transaction('))->toBeGreaterThanOrEqual(1, basename($file));
        }
    }
    expect(array_diff($writers, $allowed))->toBe([]);
});
