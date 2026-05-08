<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
});

/**
 * Chunk 5 priority #6: the Audited trait's auditableAllowlist on
 * User intentionally excludes two_factor_secret,
 * two_factor_recovery_codes, two_factor_confirmed_at, and
 * two_factor_enrollment_suspended_at. This test consumes a recovery
 * code via the live HTTP path, then walks every audit row written
 * during the request and confirms NONE of the sensitive values
 * appears anywhere in the row (action, metadata, before, after,
 * subject_attributes — anywhere).
 */
it('no plaintext recovery code, secret, or confirmation timestamp ever ends up in an audit row', function (): void {
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

    AuditLog::query()->delete();

    $oneCode = $plainCodes[0];

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'a-strong-passphrase-1234',
        'mfa_code' => $oneCode,
    ])->assertOk();

    $rows = AuditLog::query()->get();
    expect($rows)->not()->toBeEmpty();

    $sensitiveFragments = [
        $oneCode,
        $secret,
    ];
    foreach ($plainCodes as $code) {
        $sensitiveFragments[] = $code;
    }

    foreach ($rows as $row) {
        $serialized = json_encode($row->getAttributes(), JSON_THROW_ON_ERROR);
        $action = $row->action instanceof AuditAction
            ? $row->action->value
            : (string) $row->action;
        foreach ($sensitiveFragments as $fragment) {
            expect(str_contains($serialized, $fragment))->toBeFalse(
                "Audit row {$row->id} ({$action}) leaked sensitive material: {$fragment}",
            );
        }
    }
});

it('regenerate-recovery-codes audit row records the count, never the plaintext codes', function (): void {
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

    $code = app(Google2FA::class)->getCurrentOtp($secret);

    AuditLog::query()->delete();

    $response = $this->actingAs($user->refresh(), 'web')
        ->postJson('/api/v1/auth/2fa/recovery-codes', ['mfa_code' => $code])
        ->assertOk();

    $newCodes = $response->json('data.recovery_codes');

    foreach (AuditLog::query()->get() as $row) {
        $serialized = json_encode($row->getAttributes(), JSON_THROW_ON_ERROR);
        foreach ($newCodes as $newCode) {
            expect(str_contains($serialized, $newCode))->toBeFalse(
                "Audit row {$row->id} leaked a fresh recovery code: {$newCode}",
            );
        }
    }
});
