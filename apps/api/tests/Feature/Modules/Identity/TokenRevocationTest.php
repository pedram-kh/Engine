<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Contracts\PwnedPasswordsClientContract;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AccountLockoutService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| AH-099 S4 — D3, the revocation set
|--------------------------------------------------------------------------
|
|   1. logout            → the token path revokes ITS token (only)
|   2. password reset    → revokes ALL the user's tokens (the session-killing
|                          equivalent the inventory found missing)
|   3. suspension        → revokes ALL the user's tokens at the single suspend
|                          site, AccountLockoutService::escalate()
|
| Each with its §5.34 set: the over-reach negative (other users' / other
| devices' tokens survive) and the non-trigger negative (the near-miss that
| must NOT revoke).
|
*/

const TR_PASSWORD = 'password-12chars';

beforeEach(function (): void {
    RateLimiter::for('auth-ip', static fn (Request $request): Limit => Limit::none());
    RateLimiter::for('auth-login-email', static fn (Request $request): Limit => Limit::none());
    RateLimiter::for('auth-password', static fn (Request $request): Limit => Limit::none());
    Cache::flush();

    app()->bind(PwnedPasswordsClientContract::class, fn () => new class implements PwnedPasswordsClientContract
    {
        public function breachCount(string $plaintextPassword): int
        {
            return 0;
        }
    });
});

function trBearer(User $user, string $name = 'creator-app:ios'): string
{
    return $user->createToken($name, ['*'])->plainTextToken;
}

function trAs(mixed $test, string $plain)
{
    app('auth')->forgetGuards(); // a real request is a fresh app (RequestGuard memoises the user)

    return $test->withHeaders(['Origin' => 'capacitor://localhost', 'Authorization' => 'Bearer '.$plain]);
}

function trWorks(mixed $test, string $plain): bool
{
    return trAs($test, $plain)->getJson('/api/v1/me')->status() === 200;
}

// =============================================================================
// 1. Logout
// =============================================================================

it('token logout → 204, revokes exactly the token presented, and it stops working', function (): void {
    $creator = User::factory()->creator()->createOne();
    $phone = trBearer($creator, 'creator-app:ios');

    expect(trWorks($this, $phone))->toBeTrue();

    trAs($this, $phone)->postJson('/api/v1/auth/logout')->assertStatus(204);

    expect(PersonalAccessToken::query()->count())->toBe(0)
        ->and(trWorks($this, $phone))->toBeFalse();
});

it('token logout over-reach negative: the creator\'s OTHER device and OTHER users\' tokens survive', function (): void {
    $creator = User::factory()->creator()->createOne();
    $other = User::factory()->creator()->createOne();

    $phone = trBearer($creator, 'creator-app:ios');
    $tablet = trBearer($creator, 'creator-app:android');
    $strangers = trBearer($other, 'creator-app:ios');

    trAs($this, $phone)->postJson('/api/v1/auth/logout')->assertStatus(204);

    expect(PersonalAccessToken::query()->count())->toBe(2)
        ->and(trWorks($this, $tablet))->toBeTrue()
        ->and(trWorks($this, $strangers))->toBeTrue()
        ->and(trWorks($this, $phone))->toBeFalse();
});

it('token logout writes the auth.logout audit row with the creator as actor (guard creator_app)', function (): void {
    $creator = User::factory()->creator()->createOne();

    trAs($this, trBearer($creator))->postJson('/api/v1/auth/logout')->assertStatus(204);

    $row = AuditLog::query()->where('action', AuditAction::AuthLogout->value)->sole();
    expect($row->actor_id)->toBe($creator->id)
        ->and($row->metadata['guard'] ?? null)->toBe('creator_app');
});

it('logout with NO credentials is 401 and revokes nothing', function (): void {
    $creator = User::factory()->creator()->createOne();
    trBearer($creator);

    $this->withHeader('Origin', 'capacitor://localhost')->postJson('/api/v1/auth/logout')->assertStatus(401);
    trAs($this, 'garbage|token')->postJson('/api/v1/auth/logout')->assertStatus(401);

    expect(PersonalAccessToken::query()->count())->toBe(1);
});

it('cookie logout is the UNCHANGED branch: it ends the session and does not touch app tokens', function (): void {
    $creator = User::factory()->creator()->createOne();
    $app = trBearer($creator);

    $this->actingAs($creator, 'web')->postJson('/api/v1/auth/logout')->assertStatus(204);

    expect(PersonalAccessToken::query()->count())->toBe(1)
        ->and(trWorks($this, $app))->toBeTrue();
});

// =============================================================================
// 2. Password reset
// =============================================================================

it('a completed password reset revokes ALL of the user\'s tokens', function (): void {
    $creator = User::factory()->creator()->createOne(['email' => 'reset@example.com']);
    $phone = trBearer($creator, 'creator-app:ios');
    $tablet = trBearer($creator, 'creator-app:android');

    expect(trWorks($this, $phone))->toBeTrue();

    $this->postJson('/api/v1/auth/reset-password', [
        'email' => 'reset@example.com',
        'token' => Password::broker()->createToken($creator),
        'password' => 'a-brand-new-passphrase-1234',
        'password_confirmation' => 'a-brand-new-passphrase-1234',
    ])->assertStatus(204);

    expect(PersonalAccessToken::query()->where('tokenable_id', $creator->id)->count())->toBe(0)
        ->and(trWorks($this, $phone))->toBeFalse()
        ->and(trWorks($this, $tablet))->toBeFalse();
});

it('password reset over-reach negative: another user\'s tokens survive', function (): void {
    $creator = User::factory()->creator()->createOne(['email' => 'reset2@example.com']);
    $bystander = User::factory()->creator()->createOne();
    trBearer($creator);
    $kept = trBearer($bystander);

    $this->postJson('/api/v1/auth/reset-password', [
        'email' => 'reset2@example.com',
        'token' => Password::broker()->createToken($creator),
        'password' => 'a-brand-new-passphrase-1234',
        'password_confirmation' => 'a-brand-new-passphrase-1234',
    ])->assertStatus(204);

    expect(PersonalAccessToken::query()->count())->toBe(1)
        ->and(trWorks($this, $kept))->toBeTrue();
});

it('password reset non-trigger negatives: an invalid token and a rejected password leave tokens intact', function (): void {
    $creator = User::factory()->creator()->createOne(['email' => 'reset3@example.com']);
    $app = trBearer($creator);

    // Bad reset token.
    $this->postJson('/api/v1/auth/reset-password', [
        'email' => 'reset3@example.com',
        'token' => 'totally-bogus-token',
        'password' => 'a-brand-new-passphrase-1234',
        'password_confirmation' => 'a-brand-new-passphrase-1234',
    ])->assertStatus(400);

    // Valid token, password rejected (too short) — never reaches the broker callback.
    $this->postJson('/api/v1/auth/reset-password', [
        'email' => 'reset3@example.com',
        'token' => Password::broker()->createToken($creator),
        'password' => 'short',
        'password_confirmation' => 'short',
    ])->assertStatus(422);

    expect(PersonalAccessToken::query()->count())->toBe(1)
        ->and(trWorks($this, $app))->toBeTrue();
});

it('requesting a reset (forgot-password) does not revoke anything — only COMPLETING one does', function (): void {
    $creator = User::factory()->creator()->createOne(['email' => 'forgot@example.com']);
    trBearer($creator);

    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'forgot@example.com'])->assertStatus(204);

    expect(PersonalAccessToken::query()->count())->toBe(1);
});

// =============================================================================
// 3. Suspension — the single suspend site (AccountLockoutService::escalate)
// =============================================================================

it('escalate() revokes ALL of the user\'s tokens and the token stops working', function (): void {
    $creator = User::factory()->creator()->createOne();
    $phone = trBearer($creator, 'creator-app:ios');
    $tablet = trBearer($creator, 'creator-app:android');

    expect(trWorks($this, $phone))->toBeTrue();

    app(AccountLockoutService::class)->escalate($creator);

    expect($creator->refresh()->is_suspended)->toBeTrue()
        ->and(PersonalAccessToken::query()->where('tokenable_id', $creator->id)->count())->toBe(0)
        ->and(trWorks($this, $phone))->toBeFalse()
        ->and(trWorks($this, $tablet))->toBeFalse();
});

it('escalate() over-reach negative: another user\'s tokens survive', function (): void {
    $creator = User::factory()->creator()->createOne();
    $bystander = User::factory()->creator()->createOne();
    trBearer($creator);
    $kept = trBearer($bystander);

    app(AccountLockoutService::class)->escalate($creator);

    expect(PersonalAccessToken::query()->count())->toBe(1)
        ->and(trWorks($this, $kept))->toBeTrue();
});

it('end to end: the 24h failed-login escalation, driven through the TOKEN door, revokes the user\'s tokens', function (): void {
    $creator = User::factory()->creator()->createOne(['email' => 'burn@example.com']);
    $app = trBearer($creator);

    // Walk the lockout threshold. Clear the 15-minute temporary lock between
    // attempts so each failure reaches the password check (the 10th failure in
    // the 24h window escalates), exactly as the existing LoginTest does.
    for ($i = 0; $i < 10; $i++) {
        Cache::forget('identity:temporary-lock:burn@example.com');
        $this->withHeader('Origin', 'capacitor://localhost')->postJson('/api/v1/auth/token', [
            'email' => 'burn@example.com', 'password' => 'wrong-password', 'platform' => 'ios',
        ]);
    }

    expect($creator->refresh()->is_suspended)->toBeTrue()
        ->and(PersonalAccessToken::query()->count())->toBe(0)
        ->and(trWorks($this, $app))->toBeFalse();
});

it('escalate() non-trigger negative: a TEMPORARY lock (5 failures) revokes nothing', function (): void {
    $creator = User::factory()->creator()->createOne(['email' => 'cool@example.com']);
    $app = trBearer($creator);

    for ($i = 0; $i < 5; $i++) {
        $this->withHeader('Origin', 'capacitor://localhost')->postJson('/api/v1/auth/token', [
            'email' => 'cool@example.com', 'password' => 'wrong-password', 'platform' => 'ios',
        ]);
    }

    expect($creator->refresh()->is_suspended)->toBeFalse()
        ->and(PersonalAccessToken::query()->count())->toBe(1)
        ->and(trWorks($this, $app))->toBeTrue();
});

it('escalate() stays idempotent (§5.6): a second call on a suspended user adds no audit row', function (): void {
    $creator = User::factory()->creator()->createOne();
    trBearer($creator);

    $svc = app(AccountLockoutService::class);
    $svc->escalate($creator);
    $svc->escalate($creator->refresh());

    expect(AuditLog::query()->where('action', AuditAction::AuthAccountLockedSuspended->value)->count())->toBe(1)
        ->and(PersonalAccessToken::query()->count())->toBe(0);
});
