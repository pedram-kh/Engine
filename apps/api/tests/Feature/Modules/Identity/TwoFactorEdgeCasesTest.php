<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Middleware\EnsureMfaForAdmins;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\TwoFactorChallengeService;
use App\Modules\Identity\Services\TwoFactorEnrollmentService;
use App\Modules\Identity\Services\TwoFactorService;
use App\Modules\Identity\Services\TwoFactorVerificationThrottle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
});

// ---------------------------------------------------------------------------
// TwoFactorChallengeService — defensive guards
// ---------------------------------------------------------------------------

it('verify() returns failed for an empty mfa_code candidate', function (): void {
    /** @var User $user */
    $user = User::factory()->create();
    $service = app(TwoFactorChallengeService::class);

    $result = $service->verify($user, '   ', request());

    expect($result->passed)->toBeFalse();
});

it('consumeRecoveryCode() returns false for an empty candidate', function (): void {
    /** @var User $user */
    $user = User::factory()->create();
    $service = app(TwoFactorChallengeService::class);

    expect($service->consumeRecoveryCode($user, '', request()))->toBeFalse();
});

it('consumeRecoveryCode() returns false when the user has no stored recovery codes', function (): void {
    /** @var User $user */
    $user = User::factory()->create();
    $service = app(TwoFactorChallengeService::class);

    expect($service->consumeRecoveryCode($user, 'aaaa-bbbb-cccc-dddd', request()))->toBeFalse();
});

it('consumeRecoveryCode() returns false when the user row vanishes mid-transaction', function (): void {
    /** @var User $user */
    $user = User::factory()->create();
    // Build a real User instance whose primary key does NOT correspond
    // to any row in the DB. The lockForUpdate select returns null.
    $ghost = User::factory()->make();
    $ghost->id = 999_999_999;

    $service = app(TwoFactorChallengeService::class);

    expect($service->consumeRecoveryCode($ghost, 'aaaa-bbbb-cccc-dddd', request()))->toBeFalse();
});

// ---------------------------------------------------------------------------
// TwoFactorEnrollmentService — defensive guards
// ---------------------------------------------------------------------------

it('confirm() returns alreadyConfirmed when user already has 2FA enabled', function (): void {
    /** @var User $user */
    $user = User::factory()->withTwoFactor()->create();
    $service = app(TwoFactorEnrollmentService::class);

    $result = $service->confirm($user, 'any-token', '000000', request());

    expect($result->status->value)->toBe('already_confirmed');
});

it('disable() is a no-op when 2FA is not enabled', function (): void {
    /** @var User $user */
    $user = User::factory()->create();
    $service = app(TwoFactorEnrollmentService::class);

    $service->disable($user, request());

    $user->refresh();
    expect($user->two_factor_secret)->toBeNull();
});

// ---------------------------------------------------------------------------
// TwoFactorVerificationThrottle — idempotent suspension
// ---------------------------------------------------------------------------

it('suspendEnrollment is idempotent: a second crossing of the threshold does not re-stamp', function (): void {
    /** @var User $user */
    $user = User::factory()->create([
        'two_factor_enrollment_suspended_at' => now()->subDay(),
    ]);

    $throttle = app(TwoFactorVerificationThrottle::class);

    // Pump the counter past the hard threshold.
    for ($i = 0; $i < TwoFactorVerificationThrottle::HARD_THRESHOLD; $i++) {
        $throttle->recordFailure($user, '127.0.0.1', 'phpunit');
    }

    $user->refresh();
    // Original timestamp preserved (a day ago, not "now"-ish).
    expect($user->two_factor_enrollment_suspended_at?->isYesterday())->toBeTrue();
});

// ---------------------------------------------------------------------------
// EnsureMfaForAdmins — pass-through when no user is resolved
// ---------------------------------------------------------------------------

it('EnsureMfaForAdmins does not crash when $request->user() is null (auth guard upstream filters)', function (): void {
    config()->set('auth.admin_mfa_enforced', true);

    $middleware = app(EnsureMfaForAdmins::class);

    $response = $middleware->handle(Request::create('/__no-auth'), fn () => response()->json(['ok' => true]));

    expect($response->getStatusCode())->toBe(200);
});

// ---------------------------------------------------------------------------
// TwoFactorService.checkRecoveryCode against an alien hash format
// ---------------------------------------------------------------------------

it('checkRecoveryCode returns false rather than throwing on non-bcrypt input', function (): void {
    $service = app(TwoFactorService::class);

    expect($service->checkRecoveryCode('aaaa-bbbb-cccc-dddd', 'not-a-bcrypt-hash'))->toBeFalse();
});
