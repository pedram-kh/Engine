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

// ---------------------------------------------------------------------------
// Constant-verification-count invariant (no timing oracle)
// ---------------------------------------------------------------------------
//
// The recovery-code loop in TwoFactorChallengeService::consumeRecoveryCode()
// MUST run checkRecoveryCode() on every stored hash slot, even after a match
// is found. Short-circuiting on first match would make the bcrypt-verify
// count a function of the matched slot's position and leak it via response
// timing (~bcrypt-cost-10 ms per slot, ~80–90 ms differential between slot 0
// and slot 9 in production).
//
// This test guards the invariant by source inspection (same pattern as
// TwoFactorIsolationTest). A future engineer adding a `! $matched` short-
// circuit would have to actively edit this assertion to land their change.

it('TwoFactorChallengeService runs checkRecoveryCode on every slot (no `! $matched` short-circuit)', function (): void {
    $source = (string) file_get_contents(
        base_path('app/Modules/Identity/Services/TwoFactorChallengeService.php'),
    );

    // Match actual code patterns only, not the warning comment in the
    // source that explicitly references `! $matched` to discourage it.
    expect(preg_match('/if\s*\(\s*!\s*\$matched/', $source))->toBe(0,
        'Reintroducing `if (! $matched ...)` would restore the per-slot timing oracle.',
    );
    expect(preg_match('/&&\s*!\s*\$matched/', $source))->toBe(0,
        'Reintroducing `&& ! $matched` would restore the per-slot timing oracle.',
    );

    // Positive assertion: exactly one call site to checkRecoveryCode in the
    // file, and it lives inside the foreach (not gated by any guard).
    $callSites = (int) preg_match_all(
        '/\$this->twoFactor->checkRecoveryCode\(/',
        $source,
    );
    expect($callSites)->toBe(1);
});
