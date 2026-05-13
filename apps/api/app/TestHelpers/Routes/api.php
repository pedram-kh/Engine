<?php

declare(strict_types=1);

use App\TestHelpers\Http\Controllers\CreateAdminUserController;
use App\TestHelpers\Http\Controllers\CreateAgencyInvitationController;
use App\TestHelpers\Http\Controllers\CreateAgencyWithAdminController;
use App\TestHelpers\Http\Controllers\IssueTotpController;
use App\TestHelpers\Http\Controllers\IssueTotpFromSecretController;
use App\TestHelpers\Http\Controllers\MintVerificationTokenController;
use App\TestHelpers\Http\Controllers\NeutralizeRateLimiterController;
use App\TestHelpers\Http\Controllers\ResetClockController;
use App\TestHelpers\Http\Controllers\SetClockController;
use App\TestHelpers\Http\Middleware\VerifyTestHelperToken;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Test-helpers routes
|--------------------------------------------------------------------------
|
| Mounted by App\TestHelpers\TestHelpersServiceProvider under the `api`
| middleware group at `/api/v1/_test/*`. The provider only registers
| this file when the application environment is `local` or `testing`,
| AND only when `TEST_HELPERS_TOKEN` is non-empty — see
| `app/TestHelpers/README.md` for the operator runbook.
|
| Every route is gated by VerifyTestHelperToken, which returns a bare
| 404 when the gate is closed at request time (so a runtime config flip
| immediately closes the surface, not just a fresh boot). The route
| group's middleware is the SECOND layer of defence on top of the
| provider-level gate; both must be open for the route to fire.
|
*/

Route::prefix('_test')
    ->name('_test.')
    ->middleware(VerifyTestHelperToken::class)
    ->group(function (): void {
        Route::get('verification-token', MintVerificationTokenController::class)
            ->name('verification_token');

        Route::post('totp', IssueTotpController::class)
            ->name('totp');

        // Chunk 7.1 spec #19: the "in-flight enrollment" branch of TOTP
        // minting. The post-confirm controller above reads the secret
        // from `users.two_factor_secret`; this one accepts the secret
        // directly so the spec can mint a code from the secret the SPA
        // is showing the user before /2fa/confirm has landed (during
        // enrollment the secret lives in cache, not on the row).
        Route::post('totp/secret', IssueTotpFromSecretController::class)
            ->name('totp.secret');

        Route::post('clock', SetClockController::class)
            ->name('clock.set');

        Route::post('clock/reset', ResetClockController::class)
            ->name('clock.reset');

        // Chunk 7.1 spec #20: neutralise / restore named rate limiters.
        // POST mutates global throttle state across requests; specs
        // MUST pair with DELETE in afterEach (see controller docblock
        // and the matching `neutralizeThrottle` / `restoreThrottle`
        // Playwright fixtures).
        Route::post('rate-limiter/{name}', [NeutralizeRateLimiterController::class, 'store'])
            ->name('rate_limiter.neutralize');
        Route::delete('rate-limiter/{name}', [NeutralizeRateLimiterController::class, 'destroy'])
            ->name('rate_limiter.restore');

        // Chunk 7.6 spec subject provisioning. Production sign-up
        // rejects `platform_admin` (admin onboarding is out-of-band
        // per `docs/20-PHASE-1-SPEC.md` § 5) so the admin SPA's E2E
        // suite cannot seed its own subject through production paths.
        // This route fills exactly that gap. See the controller
        // docblock for the design discussion + Group 3 deviation #D1.
        Route::post('users/admin', CreateAdminUserController::class)
            ->name('users.admin.create');

        // Sprint 2 Chunk 1 — invitation provisioning for Chunk 2's E2E
        // accept-invitation spec. Returns the unhashed token so the spec
        // can click the magic-link without intercepting an email.
        // Mirrors CreateAdminUserController shape (chunk 7.6 pattern).
        Route::post('agencies/{agency}/invitations', CreateAgencyInvitationController::class)
            ->name('agencies.invitations.create');

        // Sprint 2 Chunk 2 — one-shot agency + admin provisioning.
        // Creates an agency_user + agency + accepted agency_admin membership
        // in a single call so brand/invitation E2E specs can sign in immediately.
        Route::post('agencies/setup', CreateAgencyWithAdminController::class)
            ->name('agencies.setup');
    });
