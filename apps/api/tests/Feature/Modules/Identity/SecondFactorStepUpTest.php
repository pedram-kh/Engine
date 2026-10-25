<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\SecondFactorOutcome;
use App\Modules\Identity\Services\SecondFactorStepUp;
use App\Modules\Identity\Services\TwoFactorService;
use App\Modules\Identity\Services\TwoFactorVerificationThrottle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| AH-104c C3 — "verified at the click"
|--------------------------------------------------------------------------
|
| One place for the second-factor check at a sensitive click, shared by the creator's deletion
| request and the admin's Erase. Sign-in's rules; a wrong code feeds the per-user throttle only.
*/

/** @return array{0: User, 1: string, 2: list<string>} */
function stepUpUser(): array
{
    $svc = app(TwoFactorService::class);
    $secret = $svc->generateSecret();
    $codes = $svc->generateRecoveryCodes();
    $user = User::factory()->platformAdmin()->createOne([
        'two_factor_secret' => $secret,
        'two_factor_recovery_codes' => array_map(fn (string $c): string => $svc->hashRecoveryCode($c), $codes),
        'two_factor_confirmed_at' => now(),
    ]);

    return [$user, $secret, $codes];
}

function stepUpRequest(): Request
{
    return Request::create('/x', 'POST');
}

it('passes a TOTP and a recovery code (burning the code), and clears the throttle on success', function (): void {
    [$user, $secret, $codes] = stepUpUser();
    $service = app(SecondFactorStepUp::class);

    $service->verify($user, '000000', stepUpRequest());
    expect($service->verify($user, app(Google2FA::class)->getCurrentOtp($secret), stepUpRequest()))->toBe(SecondFactorOutcome::Passed)
        ->and(app(TwoFactorVerificationThrottle::class)->isSoftLocked($user))->toBeFalse();

    expect($service->verify($user, $codes[0], stepUpRequest()))->toBe(SecondFactorOutcome::Passed)
        ->and($user->refresh()->two_factor_recovery_codes)->toHaveCount(count($codes) - 1)
        ->and($service->verify($user, $codes[0], stepUpRequest()))->toBe(SecondFactorOutcome::Invalid);
});

it('answers required for no code, invalid for a wrong one, rate limited at the soft cap, suspended when suspended', function (): void {
    [$user, $secret] = stepUpUser();
    $service = app(SecondFactorStepUp::class);

    expect($service->verify($user, null, stepUpRequest()))->toBe(SecondFactorOutcome::Required)
        ->and($service->verify($user, '   ', stepUpRequest()))->toBe(SecondFactorOutcome::Required);

    for ($i = 0; $i < TwoFactorVerificationThrottle::SOFT_THRESHOLD; $i++) {
        expect($service->verify($user, '000000', stepUpRequest()))->toBe(SecondFactorOutcome::Invalid);
    }
    // Past the soft cap the verifier does not run: even the right code is refused.
    expect($service->verify($user, app(Google2FA::class)->getCurrentOtp($secret), stepUpRequest()))->toBe(SecondFactorOutcome::RateLimited);

    $user->forceFill(['two_factor_enrollment_suspended_at' => now()])->saveQuietly();
    expect($service->verify($user->refresh(), app(Google2FA::class)->getCurrentOtp($secret), stepUpRequest()))->toBe(SecondFactorOutcome::EnrollmentSuspended);
});

it('suspends the enrolment at the hard cap by the same knocking as sign-in', function (): void {
    [$user] = stepUpUser();
    $service = app(SecondFactorStepUp::class);

    for ($i = 0; $i < TwoFactorVerificationThrottle::HARD_THRESHOLD; $i++) {
        $service->verify($user->refresh(), '000000', stepUpRequest());
    }

    expect($user->refresh()->hasTwoFactorEnrollmentSuspended())->toBeTrue();
});

it('passes an account with no second factor unless the caller requires one', function (): void {
    $user = User::factory()->platformAdmin()->createOne(['two_factor_confirmed_at' => null]);
    $service = app(SecondFactorStepUp::class);

    expect($service->verify($user, null, stepUpRequest()))->toBe(SecondFactorOutcome::Passed)
        ->and($service->verify($user, '123456', stepUpRequest(), requireEnrolment: true))->toBe(SecondFactorOutcome::NotEnrolled);
});
