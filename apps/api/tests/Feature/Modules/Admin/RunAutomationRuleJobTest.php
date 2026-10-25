<?php

declare(strict_types=1);

use App\Modules\Admin\Jobs\RunAutomationRuleJob;
use App\Modules\Admin\Models\AdminAutomationRule;
use App\Modules\Agencies\Mail\AdminConnectedMail;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Creators\Features\PlatformAutomationsEnabled;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Services\CreatorAgencyDirectConnectService;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Laravel\Pennant\Feature;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| AH-090 (D5) — the fire itself.
|--------------------------------------------------------------------------
|
| The job is constructed and handle()d DIRECTLY, never through the approve
| endpoint. Two reasons, both deliberate:
|
|   1. `actor_type: system` is only an honest assertion with no authenticated
|      user in the container (AuditLogger falls back to auth() when the actor is
|      null), and a job run inside an authenticated admin request would attribute
|      the row to that admin and still go green.
|   2. Every re-check in handle() is about state that CHANGED after dispatch. A
|      test that dispatches and runs in one breath cannot express that; setting
|      the world up and then invoking the handler can.
|
| The approve-endpoint side — that a dispatch happens at all, once per enabled
| rule, after the commit — is pinned in AdminCreatorApprovalAutomationTest.
*/

function autoRun(Creator $creator, AdminAutomationRule $rule): void
{
    (new RunAutomationRuleJob($creator->id, $rule->id))
        ->handle(app(CreatorAgencyDirectConnectService::class));
}

function autoCreator(bool $approved = true): Creator
{
    $user = User::factory()->create(['type' => UserType::Creator, 'preferred_language' => 'en']);
    $factory = CreatorFactory::new();
    if ($approved) {
        $factory = $factory->approved();
    }

    return $factory->createOne(['user_id' => $user->id]);
}

function autoRelation(Agency $agency, Creator $creator): ?AgencyCreatorRelation
{
    return AgencyCreatorRelation::query()->withoutGlobalScopes()
        ->where('agency_id', $agency->id)->where('creator_id', $creator->id)->first();
}

/** Assert the one-line-per-fire contract: message, outcome, and rule identity. */
function autoExpectLogged(mixed $log, string $outcome, ?AdminAutomationRule $rule = null): void
{
    $log->shouldHaveReceived('info')
        ->withArgs(function (string $message, array $context) use ($outcome, $rule): bool {
            return $message === 'platform-automations: rule fired'
                && $context['outcome'] === $outcome
                && ($rule === null || $context['rule_id'] === $rule->id);
        })
        ->once();
}

beforeEach(function (): void {
    Feature::activate(PlatformAutomationsEnabled::NAME);
});

// ---------------------------------------------------------------------------
// The happy path
// ---------------------------------------------------------------------------

it('connects the creator, audits as SYSTEM naming the rule, and bumps the fire trace', function (): void {
    Mail::fake();
    $log = Log::spy();
    $agency = Agency::factory()->createOne(['name' => 'Northwind Talent']);
    $rule = AdminAutomationRule::factory()->connectTo($agency)->createOne();
    $creator = autoCreator();

    autoRun($creator, $rule);

    expect(autoRelation($agency, $creator)?->relationship_status)->toBe(RelationshipStatus::Roster)
        // Nobody chose this agency for this creator — the rule did.
        ->and(autoRelation($agency, $creator)?->invited_by_user_id)->toBeNull();

    $audit = AuditLog::query()->where('action', AuditAction::AgencyCreatorRelationAdminConnected->value)->firstOrFail();
    expect($audit->actor_type)->toBe('system')
        ->and($audit->actor_id)->toBeNull()
        ->and($audit->reason)->toContain("rule #{$rule->id}")
        ->and($audit->reason)->toContain('Approved → Northwind Talent')
        // Q4 — provenance beyond "not a human". The reason string names the rule
        // in prose; this is what makes "what did rule X ever do" a QUERY.
        ->and($audit->metadata['fired_by'] ?? null)->toBe('automation')
        ->and($audit->metadata['automation_rule_id'] ?? null)->toBe($rule->ulid);

    // The cheap trace an operator reads instead of the audit log (D5).
    $rule->refresh();
    expect($rule->fire_count)->toBe(1)
        ->and($rule->last_fired_at)->not->toBeNull();

    Mail::assertQueued(AdminConnectedMail::class, 1);
    autoExpectLogged($log, 'connected', $rule);
});

// ---------------------------------------------------------------------------
// The re-check set — one case per skip reason (§5.34).
//
// BREAK-REVERT ANCHOR (§5.35): each of these reddens if its own re-check is
// deleted from RunAutomationRuleJob::handle() and dispatch-time state is trusted
// instead. That is the whole point of the job existing.
// ---------------------------------------------------------------------------

it('skips when the KILL SWITCH is off, even for a job already queued', function (): void {
    Mail::fake();
    $log = Log::spy();
    // Armed when the job was enqueued, disarmed before the worker got to it.
    Feature::deactivate(PlatformAutomationsEnabled::NAME);
    $agency = Agency::factory()->createOne();
    $rule = AdminAutomationRule::factory()->connectTo($agency)->createOne();
    $creator = autoCreator();

    autoRun($creator, $rule);

    expect(autoRelation($agency, $creator))->toBeNull();
    Mail::assertNothingQueued();
    autoExpectLogged($log, 'skipped:feature_disabled');
});

it('skips when the rule was DISABLED after dispatch', function (): void {
    Mail::fake();
    $log = Log::spy();
    $agency = Agency::factory()->createOne();
    $rule = AdminAutomationRule::factory()->connectTo($agency)->disabled()->createOne();
    $creator = autoCreator();

    autoRun($creator, $rule);

    expect(autoRelation($agency, $creator))->toBeNull();
    autoExpectLogged($log, 'skipped:rule_disabled', $rule);
});

it('skips when the rule was DELETED after dispatch', function (): void {
    $log = Log::spy();
    $agency = Agency::factory()->createOne();
    $rule = AdminAutomationRule::factory()->connectTo($agency)->createOne();
    $creator = autoCreator();
    $ruleId = $rule->id;
    $rule->delete();

    (new RunAutomationRuleJob($creator->id, $ruleId))->handle(app(CreatorAgencyDirectConnectService::class));

    expect(autoRelation($agency, $creator))->toBeNull();
    autoExpectLogged($log, 'skipped:rule_missing');
});

it('skips when the creator was REOPENED between approval and the fire', function (): void {
    Mail::fake();
    $log = Log::spy();
    $agency = Agency::factory()->createOne();
    $rule = AdminAutomationRule::factory()->connectTo($agency)->createOne();
    // Approved at dispatch time, back to incomplete by the time the worker runs.
    $creator = autoCreator(approved: false);

    autoRun($creator, $rule);

    expect(autoRelation($agency, $creator))->toBeNull();
    Mail::assertNothingQueued();
    autoExpectLogged($log, 'skipped:creator_not_approved', $rule);

    $rule->refresh();
    expect($rule->fire_count)->toBe(0)
        ->and($rule->last_fired_at)->toBeNull();
});

it('skips when the target agency was SUSPENDED between approval and the fire (D3)', function (): void {
    Mail::fake();
    $log = Log::spy();
    $agency = Agency::factory()->suspended()->createOne();
    $rule = AdminAutomationRule::factory()->connectTo($agency)->createOne();
    $creator = autoCreator();

    autoRun($creator, $rule);

    expect(autoRelation($agency, $creator))->toBeNull();
    Mail::assertNothingQueued();
    autoExpectLogged($log, 'skipped:agency_suspended', $rule);
});

it('skips when the target agency no longer exists (no FK lives inside JSON)', function (): void {
    $log = Log::spy();
    $rule = AdminAutomationRule::factory()->connectToMissingAgency()->createOne();
    $creator = autoCreator();

    autoRun($creator, $rule);

    autoExpectLogged($log, 'skipped:agency_missing', $rule);
});

it('skips a rule whose config names no agency at all', function (): void {
    $log = Log::spy();
    $rule = AdminAutomationRule::factory()->createOne(); // config: null
    $creator = autoCreator();

    autoRun($creator, $rule);

    autoExpectLogged($log, 'skipped:rule_misconfigured', $rule);
});

it('skips a hard-blacklisted pair', function (): void {
    Mail::fake();
    $log = Log::spy();
    $agency = Agency::factory()->createOne();
    $rule = AdminAutomationRule::factory()->connectTo($agency)->createOne();
    $creator = autoCreator();
    AgencyCreatorRelation::factory()->blacklisted('Hard ban')->create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::Declined,
    ]);

    autoRun($creator, $rule);

    expect(autoRelation($agency, $creator)?->relationship_status)->toBe(RelationshipStatus::Declined);
    Mail::assertNothingQueued();
    autoExpectLogged($log, 'skipped:blacklisted', $rule);
});

it('never reverses a deliberate termination or refusal (Q3)', function (string $status): void {
    Mail::fake();
    $log = Log::spy();
    $agency = Agency::factory()->createOne();
    $rule = AdminAutomationRule::factory()->connectTo($agency)->createOne();
    $creator = autoCreator();
    AgencyCreatorRelation::factory()->create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::from($status),
    ]);

    autoRun($creator, $rule);

    // An admin severed this (`ended`) or the creator refused this agency
    // (`declined`). A rule does not overrule either.
    expect(autoRelation($agency, $creator)?->relationship_status)->toBe(RelationshipStatus::from($status));
    Mail::assertNothingQueued();
    autoExpectLogged($log, 'skipped:relation_terminal', $rule);
})->with(['ended', 'declined']);

// ---------------------------------------------------------------------------
// Idempotency + overlap
// ---------------------------------------------------------------------------

it('is idempotent: a second fire on the same pair connects nothing and says why', function (): void {
    Mail::fake();
    $agency = Agency::factory()->createOne();
    $rule = AdminAutomationRule::factory()->connectTo($agency)->createOne();
    $creator = autoCreator();

    autoRun($creator, $rule);

    $log = Log::spy();
    autoRun($creator, $rule);

    expect(AgencyCreatorRelation::query()->withoutGlobalScopes()->where('creator_id', $creator->id)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', AuditAction::AgencyCreatorRelationAdminConnected->value)->count())->toBe(1);

    // The trace counts real fires, not attempts.
    $rule->refresh();
    expect($rule->fire_count)->toBe(1);

    Mail::assertQueued(AdminConnectedMail::class, 1);
    autoExpectLogged($log, 'skipped:already_connected', $rule);
});

it('two rules naming the SAME agency are safe — the second is a no-op', function (): void {
    Mail::fake();
    $agency = Agency::factory()->createOne();
    $first = AdminAutomationRule::factory()->connectTo($agency)->createOne(['name' => 'Rule one']);
    $second = AdminAutomationRule::factory()->connectTo($agency)->createOne(['name' => 'Rule two']);
    $creator = autoCreator();

    autoRun($creator, $first);
    autoRun($creator, $second);

    // This is why D1 ships no uniqueness constraint: overlap is absorbed, not
    // prevented.
    expect(AgencyCreatorRelation::query()->withoutGlobalScopes()->where('creator_id', $creator->id)->count())->toBe(1);
    expect($first->refresh()->fire_count)->toBe(1)
        ->and($second->refresh()->fire_count)->toBe(0);
});

it('two rules naming DIFFERENT agencies both fire — one creator, two rosters', function (): void {
    Mail::fake();
    $northwind = Agency::factory()->createOne(['name' => 'Northwind']);
    $eastwind = Agency::factory()->createOne(['name' => 'Eastwind']);
    $ruleA = AdminAutomationRule::factory()->connectTo($northwind)->createOne();
    $ruleB = AdminAutomationRule::factory()->connectTo($eastwind)->createOne();
    $creator = autoCreator();

    autoRun($creator, $ruleA);
    autoRun($creator, $ruleB);

    expect(autoRelation($northwind, $creator)?->relationship_status)->toBe(RelationshipStatus::Roster)
        ->and(autoRelation($eastwind, $creator)?->relationship_status)->toBe(RelationshipStatus::Roster);
    Mail::assertQueued(AdminConnectedMail::class, 2);
});

// ---------------------------------------------------------------------------
// The line itself
// ---------------------------------------------------------------------------

it('logs one line per fire, carrying the identity an operator needs', function (): void {
    Mail::fake();
    $log = Log::spy();
    $agency = Agency::factory()->createOne(['name' => 'Northwind Talent']);
    $rule = AdminAutomationRule::factory()->connectTo($agency)->createOne();
    $creator = autoCreator();

    autoRun($creator, $rule);

    // BREAK-REVERT ANCHOR (§5.35): delete the log() call from the success path
    // and this reddens. A fire an operator cannot see happened is the failure
    // mode this chunk's observability exists to prevent.
    $log->shouldHaveReceived('info')
        ->withArgs(function (string $message, array $context) use ($rule, $creator, $agency): bool {
            return $message === 'platform-automations: rule fired'
                && $context['outcome'] === 'connected'
                && $context['connect_outcome'] === 'connected'
                && $context['rule_id'] === $rule->id
                && $context['rule_name'] === $rule->name
                && $context['event'] === 'creator.approved'
                && $context['action'] === 'connect_to_agency'
                && $context['creator_id'] === $creator->id
                && $context['creator_ulid'] === $creator->ulid
                && $context['agency_id'] === $agency->ulid
                && $context['fire_count'] === 1;
        })
        ->once();
});

it('distinguishes a job-level refusal from a connect-level one', function (): void {
    $log = Log::spy();
    $rule = AdminAutomationRule::factory()->connectToMissingAgency()->createOne();

    autoRun(autoCreator(), $rule);

    // `connect_outcome` is null because the connect service was never reached —
    // an operator can tell "the rule is broken" from "the connect refused".
    $log->shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context): bool => $context['outcome'] === 'skipped:agency_missing'
            && $context['connect_outcome'] === null)
        ->once();
});

it('skips a creator who is leaving: a request standing, a claim, or erased — no relation, no mail, one log line', function (string $leaving): void {
    Mail::fake();
    $log = Log::spy();
    $agency = Agency::factory()->createOne();
    $rule = AdminAutomationRule::factory()->connectTo($agency)->createOne();
    $creator = autoCreator();
    User::query()->whereKey($creator->user_id)->update(match ($leaving) {
        'request pending' => ['deletion_requested_at' => now(), 'deletion_scheduled_for' => now()->addDays(14)],
        'claimed' => ['erasure_started_at' => now()],
        'erased' => ['erased_at' => now()],
        default => throw new InvalidArgumentException($leaving),
    });
    if ($leaving === 'erased') {
        Creator::query()->whereKey($creator->id)->update(['erased_at' => now()]);
    }

    autoRun($creator->refresh(), $rule);

    expect(autoRelation($agency, $creator))->toBeNull()
        ->and($rule->refresh()->fire_count)->toBe(0);
    Mail::assertNothingQueued();
    autoExpectLogged($log, 'skipped:creator_unavailable', $rule);
})->with(['request pending', 'claimed', 'erased']);
