<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Events\LoginFailed;
use App\Modules\Identity\Features\AccountDeletionEnabled;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AccountDeletionService;
use App\Modules\Identity\Services\AccountDeletionStatus;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Pennant\Feature;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| AH-104c C1-3 — a claimed account is answered as an unknown account
|--------------------------------------------------------------------------
|
| From the claim the creator cannot sign in and cannot cancel: the run deletes files before
| it erases the row, so a sign-in in that window would find an account with no files. Every
| negative has the CORRECT password, and `cancel_deletion` true (the one request that would
| otherwise undo a pending deletion). The answer must match an unknown email exactly, on both
| doors, and be audited under the same reason.
*/

const CL_PASSWORD = 'password-12chars';

beforeEach(function (): void {
    RateLimiter::for('auth-ip', static fn (Request $request): Limit => Limit::none());
    RateLimiter::for('auth-login-email', static fn (Request $request): Limit => Limit::none());
    RateLimiter::for('auth-password', static fn (Request $request): Limit => Limit::none());
    Cache::flush();
    Feature::activate(AccountDeletionEnabled::NAME);
});

function claimedCreator(string $email, bool $claimed = true, bool $requested = true): User
{
    $user = User::factory()->creator()->createOne(['email' => $email]);
    Creator::factory()->createOne(['user_id' => $user->id]);
    $stamps = [];
    if ($requested) {
        $stamps += ['deletion_requested_at' => now()->subDays(15), 'deletion_scheduled_for' => now()->subDay()];
    }
    if ($claimed) {
        $stamps += ['erasure_started_at' => now()];
    }
    $user->forceFill($stamps)->saveQuietly();

    return $user->refresh();
}

it('refuses the SPA sign-in of a claimed account, with the right password and with cancel_deletion', function (array $extra): void {
    $user = claimedCreator('claimed@example.com');

    $this->postJson('/api/v1/auth/login', ['email' => 'claimed@example.com', 'password' => CL_PASSWORD] + $extra)
        ->assertStatus(401)
        ->assertJsonPath('errors.0.code', 'auth.invalid_credentials');

    $this->assertGuest('web');
    expect($user->refresh()->deletion_requested_at)->not->toBeNull()
        ->and($user->isErasureClaimed())->toBeTrue()
        ->and(AuditLog::query()->where('action', AuditAction::AccountDeletionCancelled)->count())->toBe(0);
})->with([
    'plain' => [[]],
    'asking to cancel the deletion' => [['cancel_deletion' => true]],
]);

it('refuses the native token door for a claimed account, mints no token, and does not cancel', function (): void {
    $user = claimedCreator('claimed@example.com');

    $this->withHeader('Origin', 'capacitor://localhost')
        ->postJson('/api/v1/auth/token', ['email' => 'claimed@example.com', 'password' => CL_PASSWORD, 'platform' => 'ios', 'cancel_deletion' => true])
        ->assertStatus(401)
        ->assertJsonPath('errors.0.code', 'auth.invalid_credentials');

    expect(PersonalAccessToken::query()->count())->toBe(0)
        ->and($user->refresh()->deletion_requested_at)->not->toBeNull();
});

it('answers a claimed account exactly as it answers an unknown email', function (): void {
    claimedCreator('claimed@example.com');

    $claimed = $this->postJson('/api/v1/auth/login', ['email' => 'claimed@example.com', 'password' => CL_PASSWORD, 'cancel_deletion' => true]);
    $unknown = $this->postJson('/api/v1/auth/login', ['email' => 'never-existed@example.com', 'password' => CL_PASSWORD, 'cancel_deletion' => true]);

    // Both must be a real 401 first: two 500s would also be "equal" and prove nothing.
    expect($unknown->status())->toBe(401)
        ->and($claimed->status())->toBe($unknown->status())
        ->and($claimed->json('errors.0.code'))->toBe($unknown->json('errors.0.code'))
        ->and($claimed->json('errors.0.title'))->toBe($unknown->json('errors.0.title'))
        ->and(array_keys($claimed->json()))->toBe(array_keys($unknown->json()));
});

it('records the refusal under the same audit reason as an unknown email, and never reveals the claim', function (): void {
    claimedCreator('claimed@example.com');
    Event::fake([LoginFailed::class]);

    $this->postJson('/api/v1/auth/login', ['email' => 'claimed@example.com', 'password' => CL_PASSWORD])->assertStatus(401);

    Event::assertDispatched(
        LoginFailed::class,
        static fn (LoginFailed $e): bool => $e->reason === 'unknown_email' && $e->user === null,
    );
});

it('answers a claimed account that never asked (forced from the shell) the same way', function (): void {
    claimedCreator('forced@example.com', claimed: true, requested: false);

    $this->postJson('/api/v1/auth/login', ['email' => 'forced@example.com', 'password' => CL_PASSWORD])
        ->assertStatus(401)
        ->assertJsonPath('errors.0.code', 'auth.invalid_credentials');
});

it('still restores a pending account that is NOT claimed (the control: the creator\'s right ends at the claim, not before)', function (): void {
    $user = claimedCreator('pending@example.com', claimed: false);

    $this->postJson('/api/v1/auth/login', ['email' => 'pending@example.com', 'password' => CL_PASSWORD, 'cancel_deletion' => true])
        ->assertOk();

    expect($user->refresh()->deletion_requested_at)->toBeNull()
        ->and(AuditLog::query()->where('action', AuditAction::AccountDeletionCancelled)->count())->toBe(1);
});

it('answers the cancel itself as an unknown account when the claim lands between the check and the lock', function (): void {
    // The sign-in read the account as pending (not claimed); the claim commits; the cancel re-reads under the lock.
    $stale = claimedCreator('pending@example.com', claimed: false);
    User::query()->whereKey($stale->id)->update(['erasure_started_at' => now()]);

    expect(app(AccountDeletionService::class)->cancel($stale, null, null))->toBeFalse()
        ->and($stale->refresh()->deletion_requested_at)->not->toBeNull();
});

it('refuses a new deletion request from a claimed account as not available', function (): void {
    $user = claimedCreator('claimed@example.com', requested: false);

    $result = app(AccountDeletionService::class)->request($user, CL_PASSWORD, null, Request::create('/'));

    expect($result->status)->toBe(AccountDeletionStatus::NotAvailable)
        ->and($user->refresh()->deletion_requested_at)->toBeNull();
});

it('keeps a claimed creator hidden from agencies, as a pending one is', function (): void {
    $user = claimedCreator('claimed@example.com');
    $creator = Creator::query()->where('user_id', $user->id)->sole();

    expect($creator->isUnavailableToAgencies())->toBeTrue()
        ->and(Creator::query()->visibleToAgencies()->whereKey($creator->id)->exists())->toBeFalse()
        ->and($user->type)->toBe(UserType::Creator);
});
