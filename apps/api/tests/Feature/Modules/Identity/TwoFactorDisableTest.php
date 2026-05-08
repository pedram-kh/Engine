<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Events\TwoFactorDisabled;
use App\Modules\Identity\Events\TwoFactorRecoveryCodesRegenerated;
use App\Modules\Identity\Models\User;
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
    Cache::flush();
});

function mfaUser(): array
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

// ---------------------------------------------------------------------------
// Disable
// ---------------------------------------------------------------------------

it('POST /auth/2fa/disable wipes secret + recovery codes + confirmation timestamp atomically', function (): void {
    [$user, $secret] = mfaUser();
    $code = app(Google2FA::class)->getCurrentOtp($secret);

    $response = $this->actingAs($user, 'web')->postJson('/api/v1/auth/2fa/disable', [
        'password' => 'a-strong-passphrase-1234',
        'mfa_code' => $code,
    ]);

    $response->assertNoContent();

    $user->refresh();
    expect($user->two_factor_secret)->toBeNull()
        ->and($user->two_factor_recovery_codes)->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull()
        ->and($user->two_factor_enrollment_suspended_at)->toBeNull();
});

it('disable dispatches TwoFactorDisabled and writes mfa.disabled audit row', function (): void {
    [$user, $secret] = mfaUser();
    $code = app(Google2FA::class)->getCurrentOtp($secret);

    Event::fake([TwoFactorDisabled::class]);

    $this->actingAs($user, 'web')->postJson('/api/v1/auth/2fa/disable', [
        'password' => 'a-strong-passphrase-1234',
        'mfa_code' => $code,
    ])->assertNoContent();

    Event::assertDispatched(TwoFactorDisabled::class);
});

it('disable writes mfa.disabled audit row (event-listener split)', function (): void {
    [$user, $secret] = mfaUser();
    $code = app(Google2FA::class)->getCurrentOtp($secret);

    $this->actingAs($user, 'web')->postJson('/api/v1/auth/2fa/disable', [
        'password' => 'a-strong-passphrase-1234',
        'mfa_code' => $code,
    ])->assertNoContent();

    AuditLog::query()->where('action', AuditAction::MfaDisabled->value)->firstOrFail();
});

it('disable refuses without the password (auth.mfa.invalid_code, no leak of which factor failed)', function (): void {
    [$user, $secret] = mfaUser();
    $code = app(Google2FA::class)->getCurrentOtp($secret);

    $response = $this->actingAs($user, 'web')->postJson('/api/v1/auth/2fa/disable', [
        'password' => 'wrong-password',
        'mfa_code' => $code,
    ]);

    $response->assertStatus(401)
        ->assertJsonPath('errors.0.code', 'auth.mfa.invalid_code');

    $user->refresh();
    expect($user->two_factor_secret)->not()->toBeNull();
});

it('disable refuses without a valid mfa_code', function (): void {
    [$user] = mfaUser();

    $response = $this->actingAs($user, 'web')->postJson('/api/v1/auth/2fa/disable', [
        'password' => 'a-strong-passphrase-1234',
        'mfa_code' => '000000',
    ]);

    $response->assertStatus(401)
        ->assertJsonPath('errors.0.code', 'auth.mfa.invalid_code');

    $user->refresh();
    expect($user->two_factor_secret)->not()->toBeNull();
});

it('disable accepts a recovery code in place of TOTP', function (): void {
    [$user, , $plainCodes] = mfaUser();

    $response = $this->actingAs($user, 'web')->postJson('/api/v1/auth/2fa/disable', [
        'password' => 'a-strong-passphrase-1234',
        'mfa_code' => $plainCodes[0],
    ]);

    $response->assertNoContent();

    $user->refresh();
    expect($user->two_factor_secret)->toBeNull();
});

it('disable returns 409 auth.mfa.not_enabled when 2FA is not enabled', function (): void {
    /** @var User $user */
    $user = User::factory()->create(['password' => 'a-strong-passphrase-1234']);

    $response = $this->actingAs($user, 'web')->postJson('/api/v1/auth/2fa/disable', [
        'password' => 'a-strong-passphrase-1234',
        'mfa_code' => '000000',
    ]);

    $response->assertStatus(409)
        ->assertJsonPath('errors.0.code', 'auth.mfa.not_enabled');
});

// ---------------------------------------------------------------------------
// Regenerate recovery codes
// ---------------------------------------------------------------------------

it('POST /auth/2fa/recovery-codes returns a fresh batch and replaces the stored hashes', function (): void {
    [$user, $secret] = mfaUser();
    $previousHashes = $user->two_factor_recovery_codes;
    $code = app(Google2FA::class)->getCurrentOtp($secret);

    $response = $this->actingAs($user, 'web')->postJson('/api/v1/auth/2fa/recovery-codes', [
        'mfa_code' => $code,
    ]);

    $response->assertOk();

    $newCodes = $response->json('data.recovery_codes');
    expect($newCodes)->toBeArray()->and(count($newCodes))->toBe(10);

    $user->refresh();
    expect($user->two_factor_recovery_codes)->not()->toBe($previousHashes)
        ->and(count($user->two_factor_recovery_codes ?? []))->toBe(10);
});

it('regenerate dispatches TwoFactorRecoveryCodesRegenerated and writes audit with code count, not codes', function (): void {
    [$user, $secret] = mfaUser();
    $code = app(Google2FA::class)->getCurrentOtp($secret);

    Event::fake([TwoFactorRecoveryCodesRegenerated::class]);

    $response = $this->actingAs($user, 'web')->postJson('/api/v1/auth/2fa/recovery-codes', [
        'mfa_code' => $code,
    ])->assertOk();

    Event::assertDispatched(TwoFactorRecoveryCodesRegenerated::class, fn ($e) => $e->codeCount === 10);

    $newCodes = $response->json('data.recovery_codes');

    // Audit assertion via separate test (Event::fake() swallows the listener).
    expect($newCodes)->toBeArray();
});

it('writes mfa.recovery_codes_regenerated audit row with code_count metadata, never the plaintext', function (): void {
    [$user, $secret] = mfaUser();
    $code = app(Google2FA::class)->getCurrentOtp($secret);

    $response = $this->actingAs($user, 'web')->postJson('/api/v1/auth/2fa/recovery-codes', [
        'mfa_code' => $code,
    ])->assertOk();

    $newCodes = $response->json('data.recovery_codes');

    $row = AuditLog::query()->where('action', AuditAction::MfaRecoveryCodesRegenerated->value)->firstOrFail();

    expect($row->metadata['code_count'] ?? null)->toBe(10);

    $serialized = json_encode($row->getAttributes(), JSON_THROW_ON_ERROR);
    foreach ($newCodes as $newCode) {
        expect(str_contains($serialized, $newCode))->toBeFalse();
    }
});

it('regenerate refuses without a valid mfa_code', function (): void {
    [$user] = mfaUser();

    $response = $this->actingAs($user, 'web')->postJson('/api/v1/auth/2fa/recovery-codes', [
        'mfa_code' => '000000',
    ]);

    $response->assertStatus(401)
        ->assertJsonPath('errors.0.code', 'auth.mfa.invalid_code');
});

it('regenerate returns 409 auth.mfa.not_enabled when 2FA is not enabled', function (): void {
    /** @var User $user */
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'web')->postJson('/api/v1/auth/2fa/recovery-codes', [
        'mfa_code' => '000000',
    ]);

    $response->assertStatus(409)
        ->assertJsonPath('errors.0.code', 'auth.mfa.not_enabled');
});
