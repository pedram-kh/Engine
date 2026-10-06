<?php

declare(strict_types=1);

use App\Modules\Agencies\Database\Factories\AgencyFactory;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Events\UserLoggedIn;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\TwoFactorService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| AH-099 S2 — POST /api/v1/auth/token (the token door, D1)
|--------------------------------------------------------------------------
|
| The door reuses AuthService's whole gate graph, so every gate gets its
| §5.34 case here: each negative is eligible in every respect except one,
| and EACH asserts that zero tokens were minted — the property that matters.
| Requests carry the Capacitor shell's non-stateful origin.
|
*/

const TK_PASSWORD = 'password-12chars';
const TK_MFA_PASSWORD = 'a-strong-passphrase-1234';

beforeEach(function (): void {
    RateLimiter::for('auth-ip', static fn (Request $request): Limit => Limit::none());
    RateLimiter::for('auth-login-email', static fn (Request $request): Limit => Limit::none());
    Cache::flush();

    config()->set('app.frontend_main_url', 'http://127.0.0.1:5173');
    config()->set('app.frontend_admin_url', 'http://127.0.0.1:5174');
});

function tkPost(mixed $test, array $body, string $path = '/api/v1/auth/token')
{
    return $test->withHeader('Origin', 'capacitor://localhost')->postJson($path, $body);
}

function tkTokenCount(): int
{
    return PersonalAccessToken::query()->count();
}

/** @return array{0: User, 1: string} [user, totp secret] */
function tkMfaCreator(): array
{
    /** @var User $user */
    $user = User::factory()->creator()->create(['password' => TK_MFA_PASSWORD]);

    $svc = app(TwoFactorService::class);
    $secret = $svc->generateSecret();
    $hashed = array_map(fn (string $c) => $svc->hashRecoveryCode($c), $svc->generateRecoveryCodes());

    $user->forceFill([
        'two_factor_secret' => $secret,
        'two_factor_recovery_codes' => $hashed,
        'two_factor_confirmed_at' => now(),
    ])->saveQuietly();

    return [$user->refresh(), $secret];
}

// -----------------------------------------------------------------------------
// Happy path
// -----------------------------------------------------------------------------

it('mints a per-device token for a creator and returns it once with the user resource', function (): void {
    Carbon::setTestNow('2026-02-01 09:00:00');
    $user = User::factory()->creator()->createOne(['email' => 'cee@example.com']);
    Event::fake([UserLoggedIn::class]);

    $response = tkPost($this, ['email' => 'cee@example.com', 'password' => TK_PASSWORD, 'platform' => 'ios']);

    $response->assertOk()
        ->assertJsonPath('data.id', $user->ulid)
        ->assertJsonPath('data.attributes.email', 'cee@example.com')
        ->assertJsonPath('meta.token_type', 'Bearer')
        ->assertJsonPath('meta.expires_at', Carbon::now()->addDays(90)->toIso8601String())
        ->assertJsonMissingPath('data.attributes.password');

    expect($response->json('meta.token'))->toBeString()->toContain('|');

    $row = PersonalAccessToken::query()->sole();
    expect($row->name)->toBe('creator-app:ios')
        ->and($row->tokenable_id)->toBe($user->id)
        ->and($row->abilities)->toBe(['*']);

    // Door, not session: nothing attached to the web guard.
    $this->assertGuest('web');

    Event::assertDispatched(UserLoggedIn::class, fn (UserLoggedIn $e): bool => $e->user->is($user) && $e->guard === 'creator_app' && $e->mfa === false);
    expect($user->refresh()->last_login_at)->not->toBeNull();
});

it('names the token per platform (creator-app:android)', function (): void {
    User::factory()->creator()->createOne(['email' => 'droid@example.com']);

    tkPost($this, ['email' => 'droid@example.com', 'password' => TK_PASSWORD, 'platform' => 'android'])->assertOk();

    expect(PersonalAccessToken::query()->sole()->name)->toBe('creator-app:android');
});

it('does not attach a session even when the request arrives from a stateful origin', function (): void {
    User::factory()->creator()->createOne(['email' => 'stateful@example.com']);

    // Default TestCase origin is http://localhost (stateful → session bound).
    $this->postJson('/api/v1/auth/token', [
        'email' => 'stateful@example.com', 'password' => TK_PASSWORD, 'platform' => 'ios',
    ])->assertOk();

    $this->assertGuest('web');
    expect(tkTokenCount())->toBe(1);
});

it('writes the auth.login.succeeded audit row under guard creator_app with the creator as actor', function (): void {
    $user = User::factory()->creator()->createOne(['email' => 'audit@example.com']);

    tkPost($this, ['email' => 'audit@example.com', 'password' => TK_PASSWORD, 'platform' => 'ios'])->assertOk();

    $row = AuditLog::query()->where('action', AuditAction::AuthLoginSucceeded->value)->sole();
    expect($row->actor_id)->toBe($user->id)
        ->and($row->metadata['guard'] ?? null)->toBe('creator_app')
        ->and($row->metadata['mfa'] ?? null)->toBeFalse();
});

// -----------------------------------------------------------------------------
// §5.34 negatives — every gate, each asserting ZERO tokens
// -----------------------------------------------------------------------------

it('unknown email → 401 invalid_credentials, no token', function (): void {
    tkPost($this, ['email' => 'nobody@example.com', 'password' => TK_PASSWORD, 'platform' => 'ios'])
        ->assertStatus(401)
        ->assertJsonPath('errors.0.code', 'auth.invalid_credentials');

    expect(tkTokenCount())->toBe(0);
});

it('wrong password → 401 invalid_credentials, no token', function (): void {
    User::factory()->creator()->createOne(['email' => 'wrong@example.com']);

    tkPost($this, ['email' => 'wrong@example.com', 'password' => 'not-the-password', 'platform' => 'ios'])
        ->assertStatus(401)
        ->assertJsonPath('errors.0.code', 'auth.invalid_credentials');

    expect(tkTokenCount())->toBe(0);
});

it('temporarily locked → 423 even with the CORRECT password, no token; and the counter is SHARED with the web door', function (): void {
    User::factory()->creator()->createOne(['email' => 'locked@example.com']);

    // Five failures through the TOKEN door...
    for ($i = 0; $i < 5; $i++) {
        tkPost($this, ['email' => 'locked@example.com', 'password' => 'bad-password', 'platform' => 'ios']);
    }

    // ...lock the account for the token door with the right password...
    tkPost($this, ['email' => 'locked@example.com', 'password' => TK_PASSWORD, 'platform' => 'ios'])
        ->assertStatus(423)
        ->assertJsonPath('errors.0.code', 'auth.account_locked.temporary');

    // ...and for the WEB door too: one lockout graph, not two.
    $this->withHeader('Origin', 'http://localhost')
        ->postJson('/api/v1/auth/login', ['email' => 'locked@example.com', 'password' => TK_PASSWORD])
        ->assertStatus(423)
        ->assertJsonPath('errors.0.code', 'auth.account_locked.temporary');

    expect(tkTokenCount())->toBe(0);
});

it('suspended creator → 423 account_locked.suspended, no token', function (): void {
    User::factory()->creator()->suspended()->createOne(['email' => 'susp@example.com']);

    tkPost($this, ['email' => 'susp@example.com', 'password' => TK_PASSWORD, 'platform' => 'ios'])
        ->assertStatus(423)
        ->assertJsonPath('errors.0.code', 'auth.account_locked.suspended');

    expect(tkTokenCount())->toBe(0);
});

it('member of a suspended agency → 423 (the agency-suspension gate runs before the door gate), no token', function (): void {
    User::factory()->agencyAdmin(AgencyFactory::new()->suspended()->createOne())
        ->createOne(['email' => 'agsusp@example.com']);

    tkPost($this, ['email' => 'agsusp@example.com', 'password' => TK_PASSWORD, 'platform' => 'ios'])
        ->assertStatus(423)
        ->assertJsonPath('errors.0.code', 'auth.account_locked.suspended');

    expect(tkTokenCount())->toBe(0);
});

// --- 2FA: an enrolled creator is challenged exactly as on web, BEFORE any token ---

it('2FA enrolled, no code → 401 mfa_required (+ meta.mfa_required), no token', function (): void {
    [$user] = tkMfaCreator();

    tkPost($this, ['email' => $user->email, 'password' => TK_MFA_PASSWORD, 'platform' => 'ios'])
        ->assertStatus(401)
        ->assertJsonPath('errors.0.code', 'auth.mfa_required')
        ->assertJsonPath('errors.0.meta.mfa_required', true);

    expect(tkTokenCount())->toBe(0);
});

it('2FA enrolled, wrong code → 401 mfa.invalid_code, no token', function (): void {
    [$user] = tkMfaCreator();

    tkPost($this, ['email' => $user->email, 'password' => TK_MFA_PASSWORD, 'mfa_code' => '000000', 'platform' => 'ios'])
        ->assertStatus(401)
        ->assertJsonPath('errors.0.code', 'auth.mfa.invalid_code');

    expect(tkTokenCount())->toBe(0);
});

it('2FA enrolled, soft rate limit reached → 423 mfa.rate_limited, no token', function (): void {
    [$user] = tkMfaCreator();

    for ($i = 0; $i < 5; $i++) {
        tkPost($this, ['email' => $user->email, 'password' => TK_MFA_PASSWORD, 'mfa_code' => '000000', 'platform' => 'ios'])
            ->assertStatus(401);
    }

    tkPost($this, ['email' => $user->email, 'password' => TK_MFA_PASSWORD, 'mfa_code' => '000000', 'platform' => 'ios'])
        ->assertStatus(423)
        ->assertJsonPath('errors.0.code', 'auth.mfa.rate_limited')
        ->assertHeader('Retry-After');

    expect(tkTokenCount())->toBe(0);
});

it('2FA enrollment suspended → 423 mfa.enrollment_suspended even before a code is sent, no token', function (): void {
    [$user, $secret] = tkMfaCreator();
    $user->forceFill(['two_factor_enrollment_suspended_at' => now()])->saveQuietly();

    tkPost($this, [
        'email' => $user->email, 'password' => TK_MFA_PASSWORD,
        'mfa_code' => app(Google2FA::class)->getCurrentOtp($secret), 'platform' => 'ios',
    ])->assertStatus(423)->assertJsonPath('errors.0.code', 'auth.mfa.enrollment_suspended');

    expect(tkTokenCount())->toBe(0);
});

it('2FA enrolled, valid TOTP → token minted, and the audit row records mfa: true', function (): void {
    [$user, $secret] = tkMfaCreator();

    tkPost($this, [
        'email' => $user->email, 'password' => TK_MFA_PASSWORD,
        'mfa_code' => app(Google2FA::class)->getCurrentOtp($secret), 'platform' => 'ios',
    ])->assertOk()->assertJsonPath('meta.token_type', 'Bearer');

    expect(tkTokenCount())->toBe(1);

    $row = AuditLog::query()->where('action', AuditAction::AuthLoginSucceeded->value)->sole();
    expect($row->metadata['mfa'] ?? null)->toBeTrue();
});

// --- the creator-only door ---

it('agency user with VALID credentials → 403 auth.wrong_spa pointing at the main SPA, no token', function (): void {
    User::factory()->agencyAdmin()->createOne(['email' => 'agency@example.com']);

    tkPost($this, ['email' => 'agency@example.com', 'password' => TK_PASSWORD, 'platform' => 'ios'])
        ->assertStatus(403)
        ->assertJsonPath('errors.0.code', 'auth.wrong_spa')
        ->assertJsonPath('errors.0.meta.correct_spa_url', 'http://127.0.0.1:5173');

    expect(tkTokenCount())->toBe(0);
});

it('platform admin with VALID credentials → 403 auth.wrong_spa pointing at the admin SPA, no token', function (): void {
    User::factory()->platformAdmin()->createOne(['email' => 'root@example.com']);

    tkPost($this, ['email' => 'root@example.com', 'password' => TK_PASSWORD, 'platform' => 'ios'])
        ->assertStatus(403)
        ->assertJsonPath('errors.0.code', 'auth.wrong_spa')
        ->assertJsonPath('errors.0.meta.correct_spa_url', 'http://127.0.0.1:5174');

    expect(tkTokenCount())->toBe(0);
});

// --- request validation ---

it('rejects a missing or unknown platform with 422, no token', function (): void {
    User::factory()->creator()->createOne(['email' => 'plat@example.com']);

    tkPost($this, ['email' => 'plat@example.com', 'password' => TK_PASSWORD])->assertStatus(422);
    tkPost($this, ['email' => 'plat@example.com', 'password' => TK_PASSWORD, 'platform' => 'windows-phone'])->assertStatus(422);

    expect(tkTokenCount())->toBe(0);
});

it('is throttled by the same per-email limiter as the web login', function (): void {
    RateLimiter::for('auth-login-email', static fn (Request $request): Limit => Limit::perMinute(5)
        ->by('login:'.strtolower((string) $request->input('email', '')).':'.$request->ip()));

    $body = ['email' => 'nobody@example.com', 'password' => 'x', 'platform' => 'ios'];

    for ($i = 0; $i < 5; $i++) {
        tkPost($this, $body)->assertStatus(401);
    }

    tkPost($this, $body)->assertStatus(429);
});
