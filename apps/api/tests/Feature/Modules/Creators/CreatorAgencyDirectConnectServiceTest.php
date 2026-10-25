<?php

declare(strict_types=1);

use App\Modules\Agencies\Mail\AdminConnectedMail;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Enums\DirectConnectOutcome;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Services\CreatorAgencyDirectConnectService;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| AH-090 (D2) — Door 2 at the SERVICE level.
|--------------------------------------------------------------------------
|
| AdminCreatorConnectionTest pins the same logic through HTTP and is the
| refactor's pin — it passes untouched. This file pins what HTTP cannot reach:
| the outcome vocabulary itself, and the caller shape the automation uses
| (`actor: null` + `skipTerminalRelations: true`), which no request can produce.
|
| DELIBERATELY not `actingAs()` anywhere. The system-actor assertion is only
| honest with no authenticated user in the container, because AuditLogger falls
| back to auth() when the actor is null — a test that ran inside an
| authenticated request would attribute the row to that admin and still go
| green (AH-090 plan, C6).
*/

function dcService(): CreatorAgencyDirectConnectService
{
    return app(CreatorAgencyDirectConnectService::class);
}

function dcAdmin(): User
{
    return User::factory()->create([
        'type' => UserType::PlatformAdmin,
        'two_factor_confirmed_at' => now(),
    ]);
}

function dcCreator(bool $approved = true, string $lang = 'pt'): Creator
{
    $user = User::factory()->create(['type' => UserType::Creator, 'preferred_language' => $lang]);
    $factory = CreatorFactory::new();
    if ($approved) {
        $factory = $factory->approved();
    }

    return $factory->createOne(['user_id' => $user->id]);
}

function dcRelation(Agency $agency, Creator $creator): ?AgencyCreatorRelation
{
    return AgencyCreatorRelation::query()->withoutGlobalScopes()
        ->where('agency_id', $agency->id)->where('creator_id', $creator->id)->first();
}

/**
 * Connect-verb rows only. A bare `AuditLog::count()` also sees the rows the
 * fixtures write while building an agency and a creator, which would make
 * "nothing was audited" true for the wrong reason.
 */
function dcConnectAudits(): int
{
    return AuditLog::query()
        ->where('action', AuditAction::AgencyCreatorRelationAdminConnected->value)
        ->count();
}

// ---------------------------------------------------------------------------
// The write paths
// ---------------------------------------------------------------------------

it('connects a net-new pair: roster, admin provenance, reason-bearing audit, dual-emit', function (): void {
    Mail::fake();
    $admin = dcAdmin();
    $creator = dcCreator(lang: 'it');
    $agency = Agency::factory()->createOne(['name' => 'Northwind Talent']);

    $result = dcService()->connect($creator, $agency, $admin, 'An offline representation agreement, signed.');

    expect($result->outcome)->toBe(DirectConnectOutcome::Connected)
        ->and($result->wasNetNew)->toBeTrue()
        ->and($result->relation?->relationship_status)->toBe(RelationshipStatus::Roster)
        ->and($result->relation?->invited_by_user_id)->toBe($admin->id);

    $audit = AuditLog::query()->where('action', AuditAction::AgencyCreatorRelationAdminConnected->value)->firstOrFail();
    expect($audit->actor_id)->toBe($admin->id)
        ->and($audit->actor_type)->toBe('user')
        ->and($audit->agency_id)->toBe($agency->id)
        ->and($audit->reason)->toBe('An offline representation agreement, signed.')
        // The negative half of AH-090 Q4: the automation keys are ABSENT on a
        // human connect, so their presence is itself the provenance signal. A
        // fire that forgot to pass the rule ULID would look human here.
        ->and($audit->metadata)->not->toHaveKey('fired_by')
        ->and($audit->metadata)->not->toHaveKey('automation_rule_id');

    Mail::assertQueued(AdminConnectedMail::class, 1);
    expect(Notification::query()
        ->where('recipient_user_id', $creator->user_id)
        ->where('type', NotificationType::RelationAdminConnected->value)
        ->count())->toBe(1);
});

it('elevates an existing non-roster relation and reports it as not net-new', function (string $status): void {
    Mail::fake();
    $admin = dcAdmin();
    $creator = dcCreator();
    $agency = Agency::factory()->createOne();
    AgencyCreatorRelation::factory()->create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::from($status),
    ]);

    $result = dcService()->connect($creator, $agency, $admin, 'Offline agreement supersedes the pending state.');

    expect($result->outcome)->toBe(DirectConnectOutcome::Connected)
        ->and($result->wasNetNew)->toBeFalse()
        ->and(dcRelation($agency, $creator)?->relationship_status)->toBe(RelationshipStatus::Roster);

    expect(AgencyCreatorRelation::query()->withoutGlobalScopes()->where('creator_id', $creator->id)->count())->toBe(1);
})->with(['pending_request', 'declined', 'ended', 'prospect', 'external']);

// ---------------------------------------------------------------------------
// The refusals — one case per outcome (§5.34)
// ---------------------------------------------------------------------------

it('is an idempotent no-op on an already-rostered pair: no audit, no emission', function (): void {
    Mail::fake();
    $admin = dcAdmin();
    $creator = dcCreator();
    $agency = Agency::factory()->createOne();
    AgencyCreatorRelation::factory()->create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::Roster,
    ]);

    $result = dcService()->connect($creator, $agency, $admin, 'Attempting to re-connect an already-connected pair.');

    expect($result->outcome)->toBe(DirectConnectOutcome::AlreadyConnected)
        ->and($result->relation)->not->toBeNull()
        ->and($result->wasNetNew)->toBeFalse()
        ->and(dcConnectAudits())->toBe(0);

    Mail::assertNothingQueued();
    expect(Notification::query()->count())->toBe(0);
});

it('refuses a non-approved creator before it reads any relation row', function (): void {
    Mail::fake();
    $creator = dcCreator(approved: false);
    $agency = Agency::factory()->createOne();

    $result = dcService()->connect($creator, $agency, dcAdmin(), 'A sufficiently long offline-agreement reason.');

    expect($result->outcome)->toBe(DirectConnectOutcome::CreatorNotApproved)
        ->and($result->relation)->toBeNull()
        ->and(dcRelation($agency, $creator))->toBeNull();

    Mail::assertNothingQueued();
});

it('refuses a HARD-blacklisted pair and leaves the row untouched', function (): void {
    Mail::fake();
    $creator = dcCreator();
    $agency = Agency::factory()->createOne();
    AgencyCreatorRelation::factory()->blacklisted('Hard ban')->create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::PendingRequest,
    ]);

    $result = dcService()->connect($creator, $agency, dcAdmin(), 'Trying to connect a hard-blacklisted pair.');

    expect($result->outcome)->toBe(DirectConnectOutcome::Blacklisted)
        ->and($result->relation)->not->toBeNull()
        ->and(dcRelation($agency, $creator)?->relationship_status)->toBe(RelationshipStatus::PendingRequest);

    Mail::assertNothingQueued();
});

it('refuses a SUSPENDED agency, keyed on suspended_at rather than the is_active mirror', function (): void {
    Mail::fake();
    $creator = dcCreator();
    $agency = Agency::factory()->suspended()->createOne();

    $result = dcService()->connect($creator, $agency, dcAdmin(), 'Trying to connect into a suspended agency.');

    expect($result->outcome)->toBe(DirectConnectOutcome::AgencySuspended)
        ->and($result->relation)->toBeNull()
        ->and(dcRelation($agency, $creator))->toBeNull();

    Mail::assertNothingQueued();
});

it('reads suspension from suspended_at even when the is_active mirror disagrees', function (): void {
    Mail::fake();
    $creator = dcCreator();
    // A drifted row: the mirror says active, the SOT says suspended. The SOT wins
    // — break-revert seam for swapping isSuspended() back to `is_active`.
    $agency = Agency::factory()->createOne(['is_active' => true, 'suspended_at' => now()]);

    expect(dcService()->connect($creator, $agency, dcAdmin(), 'Connecting into a drifted-state agency.')->outcome)
        ->toBe(DirectConnectOutcome::AgencySuspended);
});

it('connects through a SOFT blacklist — soft is warn-only, and the guard says hard', function (): void {
    Mail::fake();
    $creator = dcCreator();
    $agency = Agency::factory()->createOne();
    AgencyCreatorRelation::factory()->create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::PendingRequest,
        'is_blacklisted' => true,
        'blacklist_scope' => 'agency',
        'blacklist_type' => 'soft',
        'blacklist_reason' => 'A soft warning, not a ban.',
        'blacklisted_at' => now(),
    ]);

    $result = dcService()->connect($creator, $agency, dcAdmin(), 'A soft blacklist does not block a recorded agreement.');

    expect($result->outcome)->toBe(DirectConnectOutcome::Connected)
        ->and(dcRelation($agency, $creator)?->relationship_status)->toBe(RelationshipStatus::Roster);
});

// ---------------------------------------------------------------------------
// The automation's caller shape — unreachable through HTTP
// ---------------------------------------------------------------------------

it('writes a SYSTEM-actored row when nobody acted: null actor, null provenance, mail still sent', function (): void {
    Mail::fake();
    $creator = dcCreator();
    $agency = Agency::factory()->createOne(['name' => 'Rule Target Agency']);

    $result = dcService()->connect($creator, $agency, null, 'Auto-connected by rule #1 (Approved → Northwind).');

    expect($result->outcome)->toBe(DirectConnectOutcome::Connected)
        ->and($result->relation?->invited_by_user_id)->toBeNull();

    // The provenance claim: no human, and the row says so rather than
    // borrowing whoever happened to be authenticated.
    $audit = AuditLog::query()->where('action', AuditAction::AgencyCreatorRelationAdminConnected->value)->firstOrFail();
    expect($audit->actor_type)->toBe('system')
        ->and($audit->actor_role)->toBe('system')
        ->and($audit->actor_id)->toBeNull()
        ->and($audit->reason)->toBe('Auto-connected by rule #1 (Approved → Northwind).');

    // The creator's experience is identical — the actor is simply absent.
    $notification = Notification::query()
        ->where('recipient_user_id', $creator->user_id)
        ->where('type', NotificationType::RelationAdminConnected->value)
        ->firstOrFail();
    expect($notification->actor_user_id)->toBeNull()
        ->and($notification->data['agency_name'] ?? null)->toBe('Rule Target Agency');

    Mail::assertQueued(AdminConnectedMail::class, 1);
});

it('leaves a deliberately terminal relation alone when the caller asks it to (Q3)', function (string $status): void {
    Mail::fake();
    $creator = dcCreator();
    $agency = Agency::factory()->createOne();
    AgencyCreatorRelation::factory()->create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::from($status),
    ]);

    $result = dcService()->connect(
        $creator,
        $agency,
        null,
        'Auto-connected by rule #1 (Approved → Northwind).',
        skipTerminalRelations: true,
    );

    expect($result->outcome)->toBe(DirectConnectOutcome::RelationTerminal)
        ->and($result->relation)->not->toBeNull()
        // The severed / refused state SURVIVES — this is the whole point.
        ->and(dcRelation($agency, $creator)?->relationship_status)->toBe(RelationshipStatus::from($status))
        ->and(dcConnectAudits())->toBe(0);

    Mail::assertNothingQueued();
})->with(['ended', 'declined']);

it('the asymmetry is real: the SAME terminal pair connects when the caller is a human', function (string $status): void {
    Mail::fake();
    $creator = dcCreator();
    $agency = Agency::factory()->createOne();
    AgencyCreatorRelation::factory()->create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::from($status),
    ]);

    // No `skipTerminalRelations` — a human choosing IS the consent the
    // elevation encodes (AH-090, Q3).
    $result = dcService()->connect($creator, $agency, dcAdmin(), 'The admin deliberately re-connects this pair.');

    expect($result->outcome)->toBe(DirectConnectOutcome::Connected)
        ->and(dcRelation($agency, $creator)?->relationship_status)->toBe(RelationshipStatus::Roster);
})->with(['ended', 'declined']);

it('a terminal pair that is ALSO hard-blacklisted refuses on the blacklist, not the terminal state', function (): void {
    Mail::fake();
    $creator = dcCreator();
    $agency = Agency::factory()->createOne();
    AgencyCreatorRelation::factory()->blacklisted('Hard ban')->create([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::Ended,
    ]);

    $result = dcService()->connect($creator, $agency, null, 'Auto-connected by rule #1 (Approved → Northwind).', skipTerminalRelations: true);

    // Guard ORDER is a claim, not an accident: the blacklist is the stronger
    // statement and must be the reason reported.
    expect($result->outcome)->toBe(DirectConnectOutcome::Blacklisted);
});

// ---------------------------------------------------------------------------
// The vocabulary itself
// ---------------------------------------------------------------------------

it('every outcome presents both ways: an api code and a log token', function (): void {
    foreach (DirectConnectOutcome::cases() as $outcome) {
        // `creator.unavailable` (AH-104c) is the one neutral code every agency-side door already uses.
        expect($outcome->apiCode())->toMatch('/^(connection\.|creator\.unavailable$)/')
            ->and($outcome->detail())->not->toBe('')
            ->and($outcome->httpStatus())->toBeIn([200, 422]);
    }

    // The skip-reason shape the job's structured line depends on.
    expect(DirectConnectOutcome::Connected->logOutcome())->toBe('connected')
        ->and(DirectConnectOutcome::AlreadyConnected->logOutcome())->toBe('skipped:already_connected')
        ->and(DirectConnectOutcome::RelationTerminal->logOutcome())->toBe('skipped:relation_terminal');
});
