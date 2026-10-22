<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\ImpersonationSession;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| AH-104c C1-7 (F8) — nobody is signed in as an account that is leaving
|--------------------------------------------------------------------------
|
| A creator who asked to be deleted, one an admin has claimed for erasure, and an erased one cannot
| be impersonated: `start()` refuses, and so does `claim()` for a session that was started before
| the account asked to leave. The admin search still lists them.
*/

function leavingAdmin(): User
{
    return User::factory()->create(['type' => UserType::PlatformAdmin, 'two_factor_confirmed_at' => now()]);
}

function leavingCreatorUser(array $state = []): User
{
    $user = User::factory()->creator()->create(['two_factor_confirmed_at' => now()] + $state);
    Creator::factory()->createOne(['user_id' => $user->id]);

    return $user->refresh();
}

/** @return array{user_ulid: string, reason: string} */
function leavingPayload(User $target): array
{
    return ['user_ulid' => $target->ulid, 'reason' => 'Investigating a reported bug.'];
}

it('refuses to start an impersonation of an account that asked to be deleted, was claimed for erasure, or is erased', function (string $state): void {
    $admin = leavingAdmin();
    $target = leavingCreatorUser(match ($state) {
        'pending' => ['deletion_requested_at' => now()->subDay(), 'deletion_scheduled_for' => now()->addDays(13)],
        'claimed' => ['erasure_started_at' => now()],
        'claimed without a request' => ['erasure_started_at' => now(), 'deletion_requested_at' => null],
        'erased' => ['erased_at' => now()],
        default => throw new InvalidArgumentException($state),
    });

    $response = $this->actingAs($admin, 'web_admin')->postJson('/api/v1/admin/impersonate', leavingPayload($target));

    expect($response->status())->toBe(422)
        ->and($response->json('errors.0.code'))->toBe('admin.impersonation.target_unavailable')
        ->and(ImpersonationSession::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', AuditAction::AdminImpersonationStarted)->count())->toBe(0);
})->with(['pending', 'claimed', 'claimed without a request', 'erased']);

it('refuses to CLAIM a hand-off that was started before the account asked to leave, and logs nobody in', function (): void {
    $admin = leavingAdmin();
    $target = leavingCreatorUser();
    $token = $this->actingAs($admin, 'web_admin')->postJson('/api/v1/admin/impersonate', leavingPayload($target))->assertCreated()->json('data.attributes.handoff_token');

    // The creator asks to be deleted between the admin's start and the hand-off.
    $target->forceFill(['deletion_requested_at' => now(), 'deletion_scheduled_for' => now()->addDays(14)])->saveQuietly();

    $claim = $this->postJson('/api/v1/auth/impersonation/claim', ['token' => $token]);

    expect($claim->status())->toBe(422)
        ->and($claim->json('errors.0.code'))->toBe('admin.impersonation.target_unavailable');
    $this->assertGuest('web');
    expect(ImpersonationSession::query()->sole()->claimed_at)->toBeNull();
});

it('still impersonates an ordinary creator, and an agency user (the over-reach negative)', function (): void {
    $admin = leavingAdmin();

    $this->actingAs($admin, 'web_admin')->postJson('/api/v1/admin/impersonate', leavingPayload(leavingCreatorUser()))->assertCreated();
    ImpersonationSession::query()->update(['ended_at' => now()]);
    $this->actingAs($admin, 'web_admin')->postJson('/api/v1/admin/impersonate', leavingPayload(User::factory()->agencyAdmin()->createOne()))->assertCreated();
});

it('keeps a leaving account findable by the admin search, so the admin can see who they cannot enter', function (): void {
    $admin = leavingAdmin();
    $target = leavingCreatorUser(['deletion_requested_at' => now()->subDay(), 'deletion_scheduled_for' => now()->addDays(13), 'name' => 'Leaving Person']);

    $response = $this->actingAs($admin, 'web_admin')->getJson('/api/v1/admin/impersonate/users?q='.urlencode($target->email));

    expect($response->status())->toBe(200)
        ->and(array_column((array) $response->json('data'), 'id'))->toContain($target->ulid);
});
