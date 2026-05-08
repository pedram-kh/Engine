<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Events\TwoFactorRecoveryCodeConsumed;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\TwoFactorChallengeService;
use App\Modules\Identity\Services\TwoFactorService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    RateLimiter::for('auth-ip', static fn (Request $request): Limit => Limit::none());
    RateLimiter::for('auth-login-email', static fn (Request $request): Limit => Limit::none());
    Cache::flush();
});

/**
 * Helper that returns [User, secret, plaintextRecoveryCodes] for a fresh,
 * 2FA-confirmed user. Uses the production enrollment service end-to-end
 * so the user's stored recovery hashes match what we'll submit.
 */
function makeMfaUser(): array
{
    /** @var User $user */
    $user = User::factory()->create([
        'password' => 'a-strong-passphrase-1234',
    ]);

    $secret = app(TwoFactorService::class)->generateSecret();
    $plainCodes = app(TwoFactorService::class)->generateRecoveryCodes();
    $hashedCodes = array_map(fn (string $c) => app(TwoFactorService::class)->hashRecoveryCode($c), $plainCodes);

    $user->forceFill([
        'two_factor_secret' => $secret,
        'two_factor_recovery_codes' => $hashedCodes,
        'two_factor_confirmed_at' => now(),
    ])->saveQuietly();

    return [$user->refresh(), $secret, $plainCodes];
}

it('login without mfa_code returns auth.mfa_required when 2FA is confirmed', function (): void {
    [$user] = makeMfaUser();

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'a-strong-passphrase-1234',
    ]);

    $response->assertStatus(401)
        ->assertJsonPath('errors.0.code', 'auth.mfa_required');

    $this->assertGuest('web');
});

it('login with a valid TOTP completes and stamps mfa: true on the audit row', function (): void {
    [$user, $secret] = makeMfaUser();
    $code = app(Google2FA::class)->getCurrentOtp($secret);

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'a-strong-passphrase-1234',
        'mfa_code' => $code,
    ])->assertOk();

    $this->assertAuthenticated('web');

    $row = AuditLog::query()->where('action', AuditAction::AuthLoginSucceeded->value)->firstOrFail();
    expect($row->metadata)->toBeArray()
        ->and($row->metadata['mfa'] ?? null)->toBeTrue();
});

it('login with an invalid TOTP returns auth.mfa.invalid_code without authenticating', function (): void {
    [$user] = makeMfaUser();

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'a-strong-passphrase-1234',
        'mfa_code' => '000000',
    ]);

    $response->assertStatus(401)
        ->assertJsonPath('errors.0.code', 'auth.mfa.invalid_code');

    $this->assertGuest('web');
});

it('login with a valid recovery code completes, consumes the code, and emits the consumption event + audit', function (): void {
    [$user, , $plainCodes] = makeMfaUser();
    $oneCode = $plainCodes[0];

    Event::fake([TwoFactorRecoveryCodeConsumed::class]);

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'a-strong-passphrase-1234',
        'mfa_code' => $oneCode,
    ])->assertOk();

    $this->assertAuthenticated('web');

    Event::assertDispatched(TwoFactorRecoveryCodeConsumed::class, function (TwoFactorRecoveryCodeConsumed $event) use ($user) {
        return $event->user->is($user) && $event->remainingCount === 9;
    });

    $user->refresh();
    expect(count($user->two_factor_recovery_codes ?? []))->toBe(9);
});

it('the consumed recovery code cannot be reused for a second login', function (): void {
    [$user, , $plainCodes] = makeMfaUser();
    $oneCode = $plainCodes[0];

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'a-strong-passphrase-1234',
        'mfa_code' => $oneCode,
    ])->assertOk();

    auth('web')->logout();
    session()->flush();

    $second = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'a-strong-passphrase-1234',
        'mfa_code' => $oneCode,
    ]);

    $second->assertStatus(401)
        ->assertJsonPath('errors.0.code', 'auth.mfa.invalid_code');

    $this->assertGuest('web');
});

it('writes mfa.recovery_code_consumed audit row with remaining count metadata, never the plaintext code', function (): void {
    [$user, , $plainCodes] = makeMfaUser();
    $oneCode = $plainCodes[0];

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'a-strong-passphrase-1234',
        'mfa_code' => $oneCode,
    ])->assertOk();

    $row = AuditLog::query()->where('action', AuditAction::MfaRecoveryCodeConsumed->value)->firstOrFail();

    expect($row->metadata)->toBeArray()
        ->and($row->metadata['remaining_count'] ?? null)->toBe(9);

    $serialized = json_encode($row->getAttributes(), JSON_THROW_ON_ERROR);
    expect(str_contains($serialized, $oneCode))->toBeFalse();
});

it('atomic recovery-code consumption: parallel-style attempts succeed exactly once', function (): void {
    [$user, , $plainCodes] = makeMfaUser();
    $oneCode = $plainCodes[0];

    // Race simulation: two attempts dispatched back-to-back. Because
    // consumeRecoveryCode runs inside a serialized transaction with
    // lockForUpdate, exactly one wins. SQLite serializes writes via
    // the file lock, giving the same guarantee in tests as Postgres
    // would in production.
    $first = app(TwoFactorChallengeService::class)
        ->consumeRecoveryCode($user->refresh(), $oneCode, request());
    $second = app(TwoFactorChallengeService::class)
        ->consumeRecoveryCode($user->refresh(), $oneCode, request());

    expect([$first, $second])->toEqualCanonicalizing([true, false]);

    $user->refresh();
    expect(count($user->two_factor_recovery_codes ?? []))->toBe(9);
});

it('after 5 invalid TOTP attempts the next attempt returns auth.mfa.rate_limited (423)', function (): void {
    [$user] = makeMfaUser();

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'a-strong-passphrase-1234',
            'mfa_code' => '000000',
        ])->assertStatus(401);
    }

    $rateLimited = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'a-strong-passphrase-1234',
        'mfa_code' => '000000',
    ]);

    $rateLimited->assertStatus(423)
        ->assertJsonPath('errors.0.code', 'auth.mfa.rate_limited')
        ->assertHeader('Retry-After');
});

it('after 10 invalid attempts the user 2FA enrollment is suspended and an audit row is written transactionally', function (): void {
    [$user] = makeMfaUser();

    for ($i = 0; $i < 10; $i++) {
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'a-strong-passphrase-1234',
            'mfa_code' => '000000',
        ]);
    }

    $user->refresh();
    expect($user->two_factor_enrollment_suspended_at)->not()->toBeNull();

    $row = AuditLog::query()->where('action', AuditAction::MfaEnrollmentSuspended->value)->firstOrFail();
    expect($row->subject_id)->toBe($user->id)
        ->and($row->metadata['threshold'] ?? null)->toBe(10);

    $followup = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'a-strong-passphrase-1234',
        'mfa_code' => app(Google2FA::class)->getCurrentOtp(makeMfaUser()[1]),
    ]);

    $followup->assertStatus(423)
        ->assertJsonPath('errors.0.code', 'auth.mfa.enrollment_suspended');
});

it('a 2FA-suspended user is rejected with auth.mfa.enrollment_suspended even before submitting a code', function (): void {
    [$user] = makeMfaUser();
    $user->forceFill(['two_factor_enrollment_suspended_at' => now()])->saveQuietly();

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'a-strong-passphrase-1234',
    ]);

    $response->assertStatus(423)
        ->assertJsonPath('errors.0.code', 'auth.mfa.enrollment_suspended');
});
