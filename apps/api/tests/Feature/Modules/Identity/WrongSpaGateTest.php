<?php

declare(strict_types=1);

use App\Modules\Identity\Events\LoginFailed;
use App\Modules\Identity\Events\UserLoggedIn;
use App\Modules\Identity\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

const WRONG_SPA_PASSWORD = 'password-12chars';

beforeEach(function (): void {
    RateLimiter::for('auth-ip', static fn (Request $request): Limit => Limit::none());
    RateLimiter::for('auth-login-email', static fn (Request $request): Limit => Limit::none());
    RateLimiter::for('auth-password', static fn (Request $request): Limit => Limit::none());

    config()->set('app.frontend_main_url', 'http://127.0.0.1:5173');
    config()->set('app.frontend_admin_url', 'http://127.0.0.1:5174');
});

// -----------------------------------------------------------------------------
// PlatformAdmin × web (main SPA) — the user-reported failure mode
// -----------------------------------------------------------------------------

it('rejects a platform admin who logs into the main SPA with auth.wrong_spa', function (): void {
    $admin = User::factory()->platformAdmin()->createOne(['email' => 'super@example.com']);
    $admin->forceFill(['mfa_required' => false])->saveQuietly();

    Event::fake([UserLoggedIn::class, LoginFailed::class]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'super@example.com',
        'password' => WRONG_SPA_PASSWORD,
    ]);

    $response->assertStatus(403)
        ->assertJsonPath('errors.0.code', 'auth.wrong_spa')
        ->assertJsonPath('errors.0.status', '403')
        ->assertJsonPath('errors.0.meta.correct_spa_url', 'http://127.0.0.1:5174');

    // No session attached on the wrong-SPA path — explicit posture: a
    // platform admin landing on the agency SPA must not have any side
    // effects (no last_login_at stamp, no audit row from UserLoggedIn).
    $this->assertGuest('web');
    Event::assertNotDispatched(UserLoggedIn::class);

    // We DO emit a LoginFailed for observability — the operator wants to
    // see "the wrong people are typing creds into the wrong SPA" trends
    // surface in the audit stream the same way other 4xx auth failures do.
    Event::assertDispatched(LoginFailed::class, fn (LoginFailed $event): bool => $event->reason === 'wrong_spa');
});

it('does NOT stamp last_login_at on a wrong-SPA rejection', function (): void {
    $admin = User::factory()->platformAdmin()->createOne(['email' => 'super@example.com']);
    $admin->forceFill(['mfa_required' => false, 'last_login_at' => null])->saveQuietly();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'super@example.com',
        'password' => WRONG_SPA_PASSWORD,
    ])->assertStatus(403);

    expect($admin->refresh()->last_login_at)->toBeNull();
});

// -----------------------------------------------------------------------------
// AgencyUser × web_admin (admin SPA) — symmetric case
// -----------------------------------------------------------------------------

it('rejects an agency user who logs into the admin SPA with auth.wrong_spa', function (): void {
    $agencyUser = User::factory()->createOne(['email' => 'agent@example.com']);

    Event::fake([UserLoggedIn::class, LoginFailed::class]);

    $response = $this->withMiddleware()->postJson('/api/v1/admin/auth/login', [
        'email' => 'agent@example.com',
        'password' => WRONG_SPA_PASSWORD,
    ]);

    $response->assertStatus(403)
        ->assertJsonPath('errors.0.code', 'auth.wrong_spa')
        ->assertJsonPath('errors.0.meta.correct_spa_url', 'http://127.0.0.1:5173');

    $this->assertGuest('web_admin');
    Event::assertNotDispatched(UserLoggedIn::class);
    Event::assertDispatched(LoginFailed::class, fn (LoginFailed $event): bool => $event->reason === 'wrong_spa');
});

// -----------------------------------------------------------------------------
// Correct combinations still succeed
// -----------------------------------------------------------------------------

it('allows an agency user to log into the main SPA (control case)', function (): void {
    $agencyUser = User::factory()->createOne(['email' => 'agent@example.com']);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'agent@example.com',
        'password' => WRONG_SPA_PASSWORD,
    ])->assertOk()->assertJsonPath('data.id', $agencyUser->ulid);

    $this->assertAuthenticated('web');
});

it('allows a platform admin to log into the admin SPA (control case)', function (): void {
    $admin = User::factory()->platformAdmin()->createOne(['email' => 'super@example.com']);
    $admin->forceFill(['mfa_required' => false])->saveQuietly();

    $this->withMiddleware()->postJson('/api/v1/admin/auth/login', [
        'email' => 'super@example.com',
        'password' => WRONG_SPA_PASSWORD,
    ])->assertOk()->assertJsonPath('data.id', $admin->ulid);

    $this->assertAuthenticated('web_admin');
});

// -----------------------------------------------------------------------------
// Precedence: invalid credentials → never reveal wrong_spa via probing
// -----------------------------------------------------------------------------

it('a wrong password against a platform admin email returns invalid_credentials, NOT wrong_spa', function (): void {
    // The WrongSpa gate sits AFTER credential verification on purpose: a
    // 403 wrong_spa from a wrong-password probe would let an unauthenticated
    // attacker enumerate which emails belong to platform admins. The 401
    // invalid_credentials branch must win.
    User::factory()->platformAdmin()->createOne(['email' => 'super@example.com']);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'super@example.com',
        'password' => 'wrong-password',
    ])->assertStatus(401)->assertJsonPath('errors.0.code', 'auth.invalid_credentials');
});

it('an unknown email on the main SPA returns invalid_credentials regardless of which SPA', function (): void {
    $this->postJson('/api/v1/auth/login', [
        'email' => 'nobody@example.com',
        'password' => WRONG_SPA_PASSWORD,
    ])->assertStatus(401)->assertJsonPath('errors.0.code', 'auth.invalid_credentials');
});

// -----------------------------------------------------------------------------
// Precedence: account suspended → never reveal wrong_spa
// -----------------------------------------------------------------------------

it('a suspended platform admin on the main SPA returns account_locked.suspended, NOT wrong_spa', function (): void {
    // Same logic as the wrong-password case: the suspension envelope must
    // take precedence over the SPA-mismatch envelope so a wrong-side probe
    // does not leak that a given email is a platform admin.
    $admin = User::factory()->platformAdmin()->createOne(['email' => 'super@example.com']);
    $admin->forceFill(['is_suspended' => true])->saveQuietly();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'super@example.com',
        'password' => WRONG_SPA_PASSWORD,
    ])->assertStatus(423)->assertJsonPath('errors.0.code', 'auth.account_locked.suspended');
});
