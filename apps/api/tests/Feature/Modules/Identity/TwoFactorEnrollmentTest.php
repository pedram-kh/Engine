<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Events\TwoFactorConfirmed;
use App\Modules\Identity\Events\TwoFactorEnabled;
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

it('POST /auth/2fa/enable returns provisional token + QR + manual key without mutating the user row', function (): void {
    /** @var User $user */
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'web')->postJson('/api/v1/auth/2fa/enable');

    $response->assertOk();

    $payload = $response->json('data');
    expect($payload['provisional_token'])->toBeString()->and(strlen($payload['provisional_token']))->toBeGreaterThanOrEqual(8)
        ->and($payload['otpauth_url'])->toStartWith('otpauth://totp/')
        ->and($payload['qr_code_svg'])->toContain('<?xml')
        ->and(preg_match('/^[A-Z2-7]{32}$/', $payload['manual_entry_key']))->toBe(1)
        ->and($payload['expires_in_seconds'])->toBe(600);

    $user->refresh();
    expect($user->two_factor_secret)->toBeNull()
        ->and($user->two_factor_recovery_codes)->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull();
});

it('POST /auth/2fa/enable dispatches TwoFactorEnabled', function (): void {
    Event::fake([TwoFactorEnabled::class]);

    /** @var User $user */
    $user = User::factory()->create();

    $this->actingAs($user, 'web')
        ->postJson('/api/v1/auth/2fa/enable')
        ->assertOk();

    Event::assertDispatched(TwoFactorEnabled::class, fn (TwoFactorEnabled $event) => $event->user->is($user));
});

it('writes mfa.enabled audit row on enable', function (): void {
    /** @var User $user */
    $user = User::factory()->create();

    $this->actingAs($user, 'web')->postJson('/api/v1/auth/2fa/enable')->assertOk();

    $row = AuditLog::query()->where('action', AuditAction::MfaEnabled->value)->firstOrFail();
    expect($row->actor_id)->toBe($user->id)
        ->and($row->subject_id)->toBe($user->id);
});

it('an abandoned enrollment leaves the user row clean (provisional state evicts from cache)', function (): void {
    /** @var User $user */
    $user = User::factory()->create();

    $this->actingAs($user, 'web')->postJson('/api/v1/auth/2fa/enable')->assertOk();

    Cache::flush();

    $user->refresh();
    expect($user->two_factor_secret)->toBeNull()
        ->and($user->two_factor_recovery_codes)->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull();
});

it('POST /auth/2fa/confirm with a valid code persists secret + recovery codes + timestamp', function (): void {
    /** @var User $user */
    $user = User::factory()->create();

    $enable = $this->actingAs($user, 'web')->postJson('/api/v1/auth/2fa/enable')->assertOk();

    $secret = $enable->json('data.manual_entry_key');
    $token = $enable->json('data.provisional_token');
    $code = app(Google2FA::class)->getCurrentOtp($secret);

    $confirm = $this->actingAs($user, 'web')->postJson('/api/v1/auth/2fa/confirm', [
        'provisional_token' => $token,
        'code' => $code,
    ]);

    $confirm->assertOk();

    $codes = $confirm->json('data.recovery_codes');
    expect($codes)->toBeArray()->and(count($codes))->toBe(10);

    $user->refresh();
    expect($user->two_factor_secret)->toBe($secret)
        ->and($user->two_factor_confirmed_at)->not()->toBeNull()
        ->and($user->two_factor_recovery_codes)->toBeArray()
        ->and(count($user->two_factor_recovery_codes ?? []))->toBe(10);

    foreach ($user->two_factor_recovery_codes ?? [] as $hash) {
        expect(str_starts_with((string) $hash, '$2y$'))->toBeTrue();
    }
});

it('POST /auth/2fa/confirm with an invalid code returns 400 and does not persist secret', function (): void {
    /** @var User $user */
    $user = User::factory()->create();

    $enable = $this->actingAs($user, 'web')->postJson('/api/v1/auth/2fa/enable')->assertOk();

    $confirm = $this->actingAs($user, 'web')->postJson('/api/v1/auth/2fa/confirm', [
        'provisional_token' => $enable->json('data.provisional_token'),
        'code' => '000000',
    ]);

    $confirm->assertStatus(400)
        ->assertJsonPath('errors.0.code', 'auth.mfa.invalid_code');

    $user->refresh();
    expect($user->two_factor_secret)->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull();
});

it('POST /auth/2fa/confirm with a missing or expired provisional token returns 410', function (): void {
    /** @var User $user */
    $user = User::factory()->create();

    $confirm = $this->actingAs($user, 'web')->postJson('/api/v1/auth/2fa/confirm', [
        'provisional_token' => '01HXXXXXXXXXXXXXXXXXXXXXXX',
        'code' => '000000',
    ]);

    $confirm->assertStatus(410)
        ->assertJsonPath('errors.0.code', 'auth.mfa.provisional_expired');
});

it('POST /auth/2fa/confirm with already-enabled user returns 409', function (): void {
    /** @var User $user */
    $user = User::factory()->create();

    $enable = $this->actingAs($user, 'web')->postJson('/api/v1/auth/2fa/enable')->assertOk();
    $secret = $enable->json('data.manual_entry_key');
    $token = $enable->json('data.provisional_token');
    $code = app(Google2FA::class)->getCurrentOtp($secret);

    $this->actingAs($user, 'web')->postJson('/api/v1/auth/2fa/confirm', [
        'provisional_token' => $token,
        'code' => $code,
    ])->assertOk();

    $reEnable = $this->actingAs($user->refresh(), 'web')->postJson('/api/v1/auth/2fa/enable');
    $reEnable->assertStatus(409)->assertJsonPath('errors.0.code', 'auth.mfa.already_enabled');
});

it('POST /auth/2fa/confirm dispatches TwoFactorConfirmed', function (): void {
    /** @var User $user */
    $user = User::factory()->create();

    $enable = $this->actingAs($user, 'web')->postJson('/api/v1/auth/2fa/enable')->assertOk();
    $secret = $enable->json('data.manual_entry_key');
    $token = $enable->json('data.provisional_token');
    $code = app(Google2FA::class)->getCurrentOtp($secret);

    Event::fake([TwoFactorConfirmed::class]);

    $this->actingAs($user, 'web')->postJson('/api/v1/auth/2fa/confirm', [
        'provisional_token' => $token,
        'code' => $code,
    ])->assertOk();

    Event::assertDispatched(TwoFactorConfirmed::class, fn (TwoFactorConfirmed $event) => $event->user->is($user));
});

it('writes mfa.confirmed audit row after successful confirmation', function (): void {
    /** @var User $user */
    $user = User::factory()->create();

    $enable = $this->actingAs($user, 'web')->postJson('/api/v1/auth/2fa/enable')->assertOk();
    $secret = $enable->json('data.manual_entry_key');
    $token = $enable->json('data.provisional_token');
    $code = app(Google2FA::class)->getCurrentOtp($secret);

    $this->actingAs($user, 'web')->postJson('/api/v1/auth/2fa/confirm', [
        'provisional_token' => $token,
        'code' => $code,
    ])->assertOk();

    $row = AuditLog::query()->where('action', AuditAction::MfaConfirmed->value)->firstOrFail();
    expect($row->actor_id)->toBe($user->id);
});

it('confirms with the freshly minted secret (independent verification path)', function (): void {
    /** @var User $user */
    $user = User::factory()->create();

    $enable = $this->actingAs($user, 'web')->postJson('/api/v1/auth/2fa/enable')->assertOk();
    $secret = $enable->json('data.manual_entry_key');

    expect(app(TwoFactorService::class)->verifyTotp($secret, app(Google2FA::class)->getCurrentOtp($secret)))->toBeTrue();
});
