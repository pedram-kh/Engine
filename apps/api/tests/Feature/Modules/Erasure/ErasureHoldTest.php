<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Creators\Models\Creator;
use App\Modules\Erasure\Claim\ClaimMode;
use App\Modules\Erasure\Claim\ErasureClaimer;
use App\Modules\Erasure\Guards\ErasureRefused;
use App\Modules\Erasure\Hold\ErasureHold;
use App\Modules\Erasure\Hold\ErasureHoldRefused;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AccountDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| AH-104c C2-1 — the legal hold: service and endpoints
|--------------------------------------------------------------------------
|
| A hold stops the erasure gate in every mode, needs a reason, is invisible to the creator,
| survives a cancelled request, and is idempotent and locked. The endpoints are platform-admin
| only, behind the MFA gate.
*/

function holdCreator(array $user = [], bool $requested = true): User
{
    $account = User::factory()->creator()->createOne($user + ($requested ? [
        'deletion_requested_at' => now()->subDays(15),
        'deletion_scheduled_for' => now()->subDay(),
    ] : []));
    Creator::factory()->createOne(['user_id' => $account->id]);

    return $account->refresh();
}

function holdAdmin(): User
{
    return User::factory()->platformAdmin()->createOne(['two_factor_confirmed_at' => now()]);
}

function holdAuditRows(): int
{
    return AuditLog::query()->whereIn('action', [AuditAction::AccountErasureHeld, AuditAction::AccountErasureHoldReleased])->count();
}

/** @return array<string, mixed> */
function holdColumns(User $user): array
{
    return (array) DB::table('users')->where('id', $user->id)->first([
        'erasure_held_at', 'erasure_held_by_user_id', 'deletion_requested_at', 'deletion_scheduled_for',
    ]);
}

// ── The service ─────────────────────────────────────────────────────────────

it('holds an account: stamps it with the admin, writes one audit row with the admin as actor and the reason', function (): void {
    $user = holdCreator();
    $admin = holdAdmin();

    $changed = app(ErasureHold::class)->hold($user, $admin, 'Open legal matter, reference on file');

    $row = AuditLog::query()->where('action', AuditAction::AccountErasureHeld)->sole();

    expect($changed)->toBeTrue()
        ->and($user->refresh()->isErasureHeld())->toBeTrue()
        ->and($user->erasure_held_by_user_id)->toBe($admin->id)
        ->and($row->actor_id)->toBe($admin->id)
        ->and($row->subject_ulid)->toBe($user->ulid)
        ->and($row->reason)->toBe('Open legal matter, reference on file');
});

it('holds an account that never asked to be deleted (a legal obligation does not wait for the person)', function (): void {
    $user = holdCreator(requested: false);

    expect(app(ErasureHold::class)->hold($user, holdAdmin(), 'Regulator request, reference on file'))->toBeTrue()
        ->and($user->refresh()->isErasureHeld())->toBeTrue()
        ->and($user->deletion_requested_at)->toBeNull();
});

it('is idempotent: holding a held account, or releasing one that is not held, changes and writes nothing', function (): void {
    $user = holdCreator();
    $admin = holdAdmin();
    $service = app(ErasureHold::class);

    expect($service->release($user, $admin, 'Nothing to release here'))->toBeFalse()
        ->and(holdAuditRows())->toBe(0);

    Carbon::setTestNow('2026-10-25 10:00:00');
    $service->hold($user, $admin, 'First placement of the hold');
    $first = holdColumns($user);

    Carbon::setTestNow('2026-10-25 11:00:00');
    expect($service->hold($user, holdAdmin(), 'A second placement attempt'))->toBeFalse()
        ->and(holdColumns($user))->toBe($first)
        ->and(holdAuditRows())->toBe(1);

    Carbon::setTestNow();
});

it('refuses a blank reason for both verbs and writes nothing', function (string $verb): void {
    $user = holdCreator();
    $admin = holdAdmin();
    $service = app(ErasureHold::class);
    if ($verb === 'release') {
        $service->hold($user, $admin, 'Placed so it can be released');
    }
    $before = holdAuditRows();
    $columns = holdColumns($user);

    expect(fn () => $service->{$verb}($user, $admin, "  \n "))->toThrow(ErasureHoldRefused::class)
        ->and(holdAuditRows())->toBe($before)
        ->and(holdColumns($user))->toBe($columns);
})->with(['hold', 'release']);

it('refuses an erased account, a claimed account and a non-creator', function (string $case): void {
    $user = holdCreator();
    $expected = ErasureHoldRefused::NOT_A_CREATOR;

    match ($case) {
        'erased' => [$user->forceFill(['erased_at' => now()])->saveQuietly(), $expected = ErasureHoldRefused::ERASED],
        'claimed' => [$user->forceFill(['erasure_started_at' => now()])->saveQuietly(), $expected = ErasureHoldRefused::CLAIMED],
        'agency user' => $user->forceFill(['type' => UserType::AgencyUser])->saveQuietly(),
        default => throw new InvalidArgumentException($case),
    };

    try {
        app(ErasureHold::class)->hold($user->refresh(), holdAdmin(), 'Should never be written');
        $reason = null;
    } catch (ErasureHoldRefused $e) {
        $reason = $e->reasonCode;
    }

    expect($reason)->toBe($expected)
        ->and(holdAuditRows())->toBe(0)
        ->and(holdColumns($user)['erasure_held_at'])->toBeNull();
})->with(['erased', 'claimed', 'agency user']);

it('release clears the two hold columns and leaves the request alone', function (): void {
    $user = holdCreator();
    $admin = holdAdmin();
    $service = app(ErasureHold::class);
    $service->hold($user, $admin, 'Placed so it can be released');
    $requested = holdColumns($user);

    expect($service->release($user, $admin, 'Matter closed, reference on file'))->toBeTrue();

    $after = holdColumns($user);
    $row = AuditLog::query()->where('action', AuditAction::AccountErasureHoldReleased)->sole();

    expect($after['erasure_held_at'])->toBeNull()
        ->and($after['erasure_held_by_user_id'])->toBeNull()
        ->and($after['deletion_requested_at'])->toBe($requested['deletion_requested_at'])
        ->and($after['deletion_scheduled_for'])->toBe($requested['deletion_scheduled_for'])
        ->and($row->actor_id)->toBe($admin->id)
        ->and($row->reason)->toBe('Matter closed, reference on file');
});

it('stops the erasure gate in every mode, including the operator force', function (string $mode): void {
    $user = holdCreator($mode === 'force' ? ['deletion_requested_at' => null, 'deletion_scheduled_for' => null] : []);
    $admin = holdAdmin();
    app(ErasureHold::class)->hold($user, $admin, 'Open legal matter, reference on file');

    $attempt = fn () => app(ErasureClaimer::class)->claim(
        $user->refresh(),
        $admin,
        $mode === 'force' ? ClaimMode::ForceNotRequested : ClaimMode::Normal,
        'Ready',
    );

    expect($attempt)->toThrow(ErasureRefused::class)
        ->and($user->refresh()->isErasureClaimed())->toBeFalse();

    app(ErasureHold::class)->release($user, $admin, 'Matter closed, reference on file');
    expect($attempt())->not->toBeNull();
})->with(['normal', 'force']);

it('survives the creator cancelling the request, and shows again on a re-request (ruling Q4)', function (): void {
    $user = holdCreator();
    $admin = holdAdmin();
    app(ErasureHold::class)->hold($user, $admin, 'Open legal matter, reference on file');

    expect(app(AccountDeletionService::class)->cancel($user, '203.0.113.9', 'phpunit'))->toBeTrue();

    $cancelled = holdColumns($user);
    expect($cancelled['erasure_held_at'])->not->toBeNull()
        ->and($cancelled['erasure_held_by_user_id'])->toBe($admin->id)
        ->and($cancelled['deletion_requested_at'])->toBeNull();

    // The same person asks again later: the request is back, and the hold never left.
    $user->forceFill(['deletion_requested_at' => now(), 'deletion_scheduled_for' => now()->addDays(14)])->saveQuietly();

    expect($user->refresh()->isErasureHeld())->toBeTrue();
});

it('lets the hold go without touching a cancelled request, and a request without touching the hold', function (): void {
    $user = holdCreator();
    $admin = holdAdmin();
    app(ErasureHold::class)->hold($user, $admin, 'Open legal matter, reference on file');
    app(AccountDeletionService::class)->cancel($user, null, null);

    app(ErasureHold::class)->release($user, $admin, 'Matter closed, reference on file');

    $after = holdColumns($user);
    expect($after['erasure_held_at'])->toBeNull()
        ->and($after['deletion_requested_at'])->toBeNull();
});

// ── The endpoints ───────────────────────────────────────────────────────────

dataset('hold verbs', ['hold', 'release']);

function holdUrl(User $user, string $verb): string
{
    return "/api/v1/admin/compliance/erasure-queue/{$user->ulid}/{$verb}";
}

it('401s without a session and 403s a non-admin', function (string $verb): void {
    $user = holdCreator();

    expect($this->postJson(holdUrl($user, $verb), ['reason' => 'Open legal matter, reference'])->status())->toBe(401);

    $agency = User::factory()->createOne(['type' => UserType::AgencyUser, 'two_factor_confirmed_at' => now()]);
    expect($this->actingAs($agency, 'web_admin')->postJson(holdUrl($user, $verb), ['reason' => 'Open legal matter, reference'])->status())->toBe(403)
        ->and(holdAuditRows())->toBe(0)
        ->and(holdColumns($user)['erasure_held_at'])->toBeNull();
})->with('hold verbs');

it('422s a missing, short or oversized reason', function (string $verb, mixed $reason): void {
    $user = holdCreator();

    $response = $this->actingAs(holdAdmin(), 'web_admin')->postJson(holdUrl($user, $verb), $reason === null ? [] : ['reason' => $reason]);

    // The request rejects it (a field error), not the service's backstop (a hold error).
    expect($response->status())->toBe(422)
        ->and(json_encode($response->json()))->toContain('reason')
        ->and(holdAuditRows())->toBe(0);
    expect(json_encode($response->json()))->not->toContain('admin.erasure.hold');
})->with('hold verbs')->with([null, 'too short', str_repeat('x', 1001)]);

it('404s an unknown account', function (): void {
    $this->actingAs(holdAdmin(), 'web_admin')
        ->postJson('/api/v1/admin/compliance/erasure-queue/01ARZ3NDEKTSV4RRFFQ69G5FAV/hold', ['reason' => 'Open legal matter, reference'])
        ->assertNotFound();
});

it('places and releases a hold over the API, answering with the queue row', function (): void {
    $user = holdCreator();
    $admin = holdAdmin();

    $held = $this->actingAs($admin, 'web_admin')->postJson(holdUrl($user, 'hold'), ['reason' => '  Open legal matter, reference on file  ']);

    $held->assertOk()
        ->assertJsonPath('meta.changed', true)
        ->assertJsonPath('data.id', $user->ulid)
        ->assertJsonPath('data.attributes.state', 'held')
        ->assertJsonPath('data.attributes.held', true)
        ->assertJsonPath('data.attributes.held_by', $admin->name);

    $audit = AuditLog::query()->where('action', AuditAction::AccountErasureHeld)->sole();
    expect($audit->actor_id)->toBe($admin->id)
        ->and($audit->reason)->toBe('Open legal matter, reference on file');

    $again = $this->actingAs($admin, 'web_admin')->postJson(holdUrl($user, 'hold'), ['reason' => 'A second placement attempt']);
    $again->assertOk()->assertJsonPath('meta.changed', false);
    expect(holdAuditRows())->toBe(1);

    $released = $this->actingAs($admin, 'web_admin')->postJson(holdUrl($user, 'release'), ['reason' => 'Matter closed, reference on file']);
    $released->assertOk()
        ->assertJsonPath('meta.changed', true)
        ->assertJsonPath('data.attributes.state', 'ready')
        ->assertJsonPath('data.attributes.held', false);
    expect(holdAuditRows())->toBe(2);
});

it('answers a held creator whose request is still ahead with the Held state, and a held creator who never asked too', function (): void {
    $admin = holdAdmin();
    $pending = holdCreator(['deletion_scheduled_for' => now()->addDays(5)]);
    $never = holdCreator(requested: false);

    $a = $this->actingAs($admin, 'web_admin')->postJson(holdUrl($pending, 'hold'), ['reason' => 'Open legal matter, reference on file']);
    $b = $this->actingAs($admin, 'web_admin')->postJson(holdUrl($never, 'hold'), ['reason' => 'Open legal matter, reference on file']);

    expect($a->json('data.attributes.state'))->toBe('held')
        ->and($b->json('data.attributes.state'))->toBe('held')
        ->and($b->json('data.attributes.requested_at'))->toBeNull();
});

it('refuses a claimed account with 409 and an erased or non-creator account with 422, writing nothing', function (string $case, int $status, string $code): void {
    $user = holdCreator();
    match ($case) {
        'claimed' => $user->forceFill(['erasure_started_at' => now()])->saveQuietly(),
        'erased' => $user->forceFill(['erased_at' => now()])->saveQuietly(),
        'agency user' => $user->forceFill(['type' => UserType::AgencyUser])->saveQuietly(),
        default => throw new InvalidArgumentException($case),
    };

    $response = $this->actingAs(holdAdmin(), 'web_admin')->postJson(holdUrl($user, 'hold'), ['reason' => 'Open legal matter, reference on file']);

    expect($response->status())->toBe($status)
        ->and($response->json('errors.0.code'))->toBe($code)
        ->and(holdAuditRows())->toBe(0);
})->with([
    'claimed' => ['claimed', 409, 'admin.erasure.hold.claimed'],
    'erased' => ['erased', 422, 'admin.erasure.hold.not_available'],
    'agency user' => ['agency user', 422, 'admin.erasure.hold.not_available'],
]);

it('does not let a hold change what a payment guard says (the hold is not a blocker code)', function (): void {
    $user = holdCreator();
    CampaignAssignment::factory()->createOne([
        'creator_id' => Creator::query()->where('user_id', $user->id)->firstOrFail()->id,
        'status' => AssignmentStatus::PaymentHeld,
    ]);

    $response = $this->actingAs(holdAdmin(), 'web_admin')->postJson(holdUrl($user, 'hold'), ['reason' => 'Open legal matter, reference on file']);

    expect($response->json('data.attributes.state'))->toBe('held')
        ->and($response->json('data.attributes.blockers'))->toBe([]);
});
