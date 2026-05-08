<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Events\AccountLocked;
use App\Modules\Identity\Events\LoginFailed;
use App\Modules\Identity\Events\UserLoggedIn;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AccountLockoutService;
use App\Modules\Identity\Services\FailedLoginTracker;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Hashing\HashManager;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

const FACTORY_PASSWORD = 'password-12chars';

beforeEach(function (): void {
    // Reseed the named limiters with effectively-infinite budgets for the
    // lockout-focused suite. The rate-limit *itself* is exercised in
    // tests/Feature/Modules/Identity/AuthRateLimitTest.php — here we want
    // to verify the lockout layer in isolation without 429 noise.
    RateLimiter::for('auth-ip', static fn (Request $request): Limit => Limit::none());
    RateLimiter::for('auth-login-email', static fn (Request $request): Limit => Limit::none());
    RateLimiter::for('auth-password', static fn (Request $request): Limit => Limit::none());
});

afterEach(function (): void {
    Carbon::setTestNow();
});

// -----------------------------------------------------------------------------
// Happy path
// -----------------------------------------------------------------------------

it('logs the user in with valid credentials and returns the user resource', function (): void {
    $user = User::factory()->createOne(['email' => 'jane@example.com']);
    Event::fake([UserLoggedIn::class]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'jane@example.com',
        'password' => FACTORY_PASSWORD,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.id', $user->ulid)
        ->assertJsonPath('data.attributes.email', $user->email)
        ->assertJsonMissingPath('data.attributes.password');

    $this->assertAuthenticated('web');

    Event::assertDispatched(UserLoggedIn::class, function (UserLoggedIn $event) use ($user): bool {
        return $event->user->is($user) && $event->guard === 'web';
    });
});

it('writes auth.login.succeeded audit row on success', function (): void {
    $user = User::factory()->createOne(['email' => 'jane@example.com']);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'jane@example.com',
        'password' => FACTORY_PASSWORD,
    ])->assertOk();

    $audit = AuditLog::query()->where('action', AuditAction::AuthLoginSucceeded->value)->latest('id')->firstOrFail();
    expect($audit->actor_id)->toBe($user->id)
        ->and($audit->subject_id)->toBe($user->id);
});

it('stamps last_login_at and last_login_ip on success', function (): void {
    $user = User::factory()->createOne(['email' => 'jane@example.com']);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'jane@example.com',
        'password' => FACTORY_PASSWORD,
    ])->assertOk();

    $user->refresh();
    expect($user->last_login_at)->not()->toBeNull()
        ->and($user->last_login_ip)->not()->toBeNull();
});

it('normalises email for login (case + whitespace)', function (): void {
    User::factory()->createOne(['email' => 'jane@example.com']);

    $this->postJson('/api/v1/auth/login', [
        'email' => '  Jane@Example.COM  ',
        'password' => FACTORY_PASSWORD,
    ])->assertOk();
});

it('clears the failed-login counter on successful login', function (): void {
    User::factory()->createOne(['email' => 'clear@example.com']);

    // Two failures, then success.
    $this->postJson('/api/v1/auth/login', ['email' => 'clear@example.com', 'password' => 'wrong'])->assertStatus(401);
    $this->postJson('/api/v1/auth/login', ['email' => 'clear@example.com', 'password' => 'wrong'])->assertStatus(401);
    $this->postJson('/api/v1/auth/login', ['email' => 'clear@example.com', 'password' => FACTORY_PASSWORD])->assertOk();

    /** @var FailedLoginTracker $tracker */
    $tracker = app(FailedLoginTracker::class);
    expect($tracker->shortWindowCount('clear@example.com'))->toBe(0);
});

// -----------------------------------------------------------------------------
// Argon2id rehash on login
// -----------------------------------------------------------------------------

it('rehashes the password on login when the stored hash no longer satisfies needsRehash()', function (): void {
    // Persist a hash made with weaker Argon2id parameters (memory=8192 from
    // phpunit.xml). Then rebind the Hash manager with bumped memory cost
    // — Hash::needsRehash() now flips to true and the AuthService must
    // rehash transparently on login. This is the same migration path
    // docs/05-SECURITY-COMPLIANCE.md §6.1 calls out as the rehash trigger.
    $password = 'user-set-passphrase-1';
    $oldHash = Hash::make($password);

    $user = User::factory()->createOne([
        'email' => 'rehash@example.com',
        'password' => $oldHash,
    ]);

    config()->set('hashing.argon.memory', 32768);
    app()->singleton('hash', function ($app) {
        return new HashManager($app);
    });
    Hash::swap(app('hash'));

    expect(Hash::needsRehash($oldHash))->toBeTrue('precondition: stored hash should need rehash under bumped Argon2id cost');

    $this->postJson('/api/v1/auth/login', [
        'email' => 'rehash@example.com',
        'password' => $password,
    ])->assertOk();

    $user->refresh();
    expect($user->password)->not()->toBe($oldHash)
        ->and(Hash::needsRehash($user->password))->toBeFalse()
        ->and(str_starts_with($user->password, '$argon2id$'))->toBeTrue();
});

// -----------------------------------------------------------------------------
// auth.mfa_required — real check
// -----------------------------------------------------------------------------

it('blocks login with auth.mfa_required when the user has 2FA confirmed', function (): void {
    $user = User::factory()->withTwoFactor()->createOne(['email' => 'mfa@example.com']);
    Event::fake([UserLoggedIn::class, LoginFailed::class]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'mfa@example.com',
        'password' => FACTORY_PASSWORD,
    ]);

    $response->assertStatus(401)
        ->assertJsonPath('errors.0.code', 'auth.mfa_required')
        ->assertJsonPath('errors.0.meta.mfa_required', true);

    $this->assertGuest('web');
    Event::assertNotDispatched(UserLoggedIn::class);
    Event::assertDispatched(LoginFailed::class);
});

// -----------------------------------------------------------------------------
// Invalid credentials
// -----------------------------------------------------------------------------

it('returns auth.invalid_credentials for an unknown email', function (): void {
    $this->postJson('/api/v1/auth/login', [
        'email' => 'nobody@example.com',
        'password' => FACTORY_PASSWORD,
    ])->assertStatus(401)
        ->assertJsonPath('errors.0.code', 'auth.invalid_credentials');
});

it('returns auth.invalid_credentials for a wrong password', function (): void {
    User::factory()->createOne(['email' => 'jane@example.com']);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'jane@example.com',
        'password' => 'definitely-not-the-real-one',
    ])->assertStatus(401)
        ->assertJsonPath('errors.0.code', 'auth.invalid_credentials');
});

it('records auth.login.failed for both unknown email and wrong password', function (): void {
    User::factory()->createOne(['email' => 'jane@example.com']);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'nobody@example.com',
        'password' => 'whatever-1234567',
    ])->assertStatus(401);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'jane@example.com',
        'password' => 'wrong-password-9999',
    ])->assertStatus(401);

    expect(AuditLog::query()->where('action', AuditAction::AuthLoginFailed->value)->count())->toBe(2);
});

// -----------------------------------------------------------------------------
// 15-minute temporary lockout
// -----------------------------------------------------------------------------

it('temporarily locks on the 5th failed attempt within 15 minutes', function (): void {
    User::factory()->createOne(['email' => 'lock@example.com']);

    // 4 attempts → invalid_credentials (no lockout yet).
    for ($i = 1; $i <= 4; $i++) {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'lock@example.com',
            'password' => 'wrong-password-9999',
        ])->assertStatus(401);
    }

    // 5th attempt crosses the threshold and itself returns 423.
    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'lock@example.com',
        'password' => 'wrong-password-9999',
    ]);

    $response->assertStatus(423)
        ->assertJsonPath('errors.0.code', 'auth.account_locked.temporary')
        ->assertHeader('Retry-After');
});

it('blocks even valid credentials while temporarily locked', function (): void {
    User::factory()->createOne(['email' => 'lockvalid@example.com']);

    for ($i = 1; $i <= 5; $i++) {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'lockvalid@example.com',
            'password' => 'wrong-password-9999',
        ]);
    }

    $this->postJson('/api/v1/auth/login', [
        'email' => 'lockvalid@example.com',
        'password' => FACTORY_PASSWORD,
    ])->assertStatus(423)
        ->assertJsonPath('errors.0.code', 'auth.account_locked.temporary');
});

it('releases the temporary lock after 15 minutes', function (): void {
    Carbon::setTestNow('2026-05-08T00:00:00Z');
    User::factory()->createOne(['email' => 'lock2@example.com']);

    for ($i = 1; $i <= 5; $i++) {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'lock2@example.com',
            'password' => 'wrong-password-9999',
        ]);
    }

    $this->postJson('/api/v1/auth/login', [
        'email' => 'lock2@example.com',
        'password' => FACTORY_PASSWORD,
    ])->assertStatus(423);

    Carbon::setTestNow('2026-05-08T00:16:00Z');

    $this->postJson('/api/v1/auth/login', [
        'email' => 'lock2@example.com',
        'password' => FACTORY_PASSWORD,
    ])->assertOk();
});

// -----------------------------------------------------------------------------
// 24-hour escalation lockout
// -----------------------------------------------------------------------------

it('escalates to permanent lockout after 11 failed attempts spanning 24 hours', function (): void {
    Carbon::setTestNow('2026-05-08T00:00:00Z');
    Event::fake([AccountLocked::class]);

    $user = User::factory()->createOne(['email' => 'long@example.com']);

    // 11 failed attempts spread across the 24-hour window so the
    // short-window throttle never fires. The 10th crosses the long-window
    // threshold and escalates; the 11th finds the account suspended.
    for ($i = 1; $i <= 11; $i++) {
        Carbon::setTestNow(Carbon::parse('2026-05-08T00:00:00Z')->addMinutes(30 * $i));
        $this->postJson('/api/v1/auth/login', [
            'email' => 'long@example.com',
            'password' => 'wrong-password-9999',
        ]);
    }

    $user->refresh();
    expect($user->is_suspended)->toBeTrue()
        ->and($user->suspended_reason)->toBe('Excessive failed login attempts')
        ->and($user->suspended_at)->not->toBeNull();

    expect(AuditLog::query()->where('action', AuditAction::AuthAccountLocked->value)->count())->toBeGreaterThanOrEqual(1);
    Event::assertDispatched(AccountLocked::class);
});

it('rejects subsequent logins on a hard-locked account with auth.account_locked', function (): void {
    Carbon::setTestNow('2026-05-08T12:00:00Z');
    $user = User::factory()->suspended('Excessive failed login attempts')->createOne(['email' => 'banned@example.com']);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'banned@example.com',
        'password' => FACTORY_PASSWORD,
    ])->assertStatus(423)
        ->assertJsonPath('errors.0.code', 'auth.account_locked');

    expect($user->refresh()->is_suspended)->toBeTrue();
});

it('returns auth.account_locked.temporary while temp-locked even before checking the password', function (): void {
    User::factory()->createOne(['email' => 'preempt@example.com']);

    /** @var AccountLockoutService $lockout */
    $lockout = app(AccountLockoutService::class);
    $lockout->temporaryLock('preempt@example.com');

    $this->postJson('/api/v1/auth/login', [
        'email' => 'preempt@example.com',
        'password' => FACTORY_PASSWORD,
    ])->assertStatus(423)
        ->assertJsonPath('errors.0.code', 'auth.account_locked.temporary');
});
