<?php

declare(strict_types=1);

use App\TestHelpers\Http\Controllers\CreateAdminUserController;
use App\TestHelpers\Http\Controllers\CreateAgencyInvitationController;
use App\TestHelpers\Http\Controllers\CreateAgencyWithAdminController;
use App\TestHelpers\Http\Controllers\CreateContractedAssignmentController;
use App\TestHelpers\Http\Controllers\CreateListableCampaignController;
use App\TestHelpers\Http\Controllers\CreateListedJobController;
use App\TestHelpers\Http\Controllers\CreatePendingApplicationsController;
use App\TestHelpers\Http\Controllers\CreatePendingConnectionRequestController;
use App\TestHelpers\Http\Controllers\CreateRosterCreatorsController;
use App\TestHelpers\Http\Controllers\IssueTotpController;
use App\TestHelpers\Http\Controllers\IssueTotpFromSecretController;
use App\TestHelpers\Http\Controllers\MintVerificationTokenController;
use App\TestHelpers\Http\Controllers\NeutralizeRateLimiterController;
use App\TestHelpers\Http\Controllers\ResetClockController;
use App\TestHelpers\Http\Controllers\SetClockController;
use App\TestHelpers\Http\Controllers\SetQueueModeController;
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

        // Sprint 6 Chunk 1 — seed roster creators + accepted relations on an
        // agency so the Playwright roster spec can drive a real table + the
        // name/bio search (?q=) + the disabled filter affordances against
        // actual rows. No production path provisions a roster in one call.
        Route::post('agencies/{agency}/roster-creators', CreateRosterCreatorsController::class)
            ->name('agencies.roster_creators.create');

        // AH-058 — seed a listed campaign on the GIVEN agency plus N rostered
        // creators who have applied to it and are still pending, so the
        // agency-side applications leg can open the tab and answer real rows.
        // Agency-keyed (the roster-creators shape), deliberately a sibling of
        // creators/listed-job rather than a second mode on it: that helper is
        // creator-keyed and provisions its own agency, which no agency user can
        // sign into.
        Route::post('agencies/{agency}/pending-applications', CreatePendingApplicationsController::class)
            ->name('agencies.pending_applications.create');

        // AH-059 (D6) — a floor-complete but UNLISTED campaign on the given
        // agency, plus the signed-in creator approved and rostered against it.
        // The full-lifecycle spec starts one step earlier than every other
        // jobs-board leg: it performs the listing itself, so the helper must
        // NOT pre-list (that would delete the first step under test). Sibling
        // of pending-applications for the same reason that one is a sibling of
        // creators/listed-job — a cross-role spec signs in as the agency it
        // seeded, so the campaign has to live on an agency it controls.
        Route::post('agencies/{agency}/listable-campaign', CreateListableCampaignController::class)
            ->name('agencies.listable_campaign.create');

        // AH-069 (D9) — a CONTRACTED assignment on the given agency, with the
        // board already provisioned and the posting toggle left ON. The
        // hand-off-lifecycle leg starts here because everything before it
        // (list → apply → offer → accept) is already end-to-end elsewhere, and
        // it leaves the toggle alone because flipping it through the real
        // Settings switch is the first step under test.
        Route::post('agencies/{agency}/contracted-assignment', CreateContractedAssignmentController::class)
            ->name('agencies.contracted_assignment.create');

        // Sprint 6.6c — approve the signed-in creator + seed a pending_request
        // relation (on a fresh agency) so the creator-inbox Playwright round-
        // trip can render the approved-branch requests section and accept a
        // real request. No production path approves a self-signed-up creator.
        Route::post('creators/pending-connection-request', CreatePendingConnectionRequestController::class)
            ->name('creators.pending_connection_request.create');

        // AH-056 — approve the signed-in creator, roster them with a fresh
        // agency, and list one campaign on that agency's jobs board, so the
        // Playwright browse → detail → apply leg can start at the board. Four
        // sign-ins across two SPAs would be needed to reach this state through
        // production paths; the predicate itself is proven by the seven-case
        // feature-test set.
        Route::post('creators/listed-job', CreateListedJobController::class)
            ->name('creators.listed_job.create');

        // Sprint 3 Chunk 3 — queue mode override for E2E saga specs.
        // POST sets `config('queue.default')` for subsequent requests
        // (cached, sticky); DELETE clears the override. The matching
        // middleware {@see ApplyTestQueueModeMiddleware} applies the
        // cached value on every request. Specs MUST pair POST + DELETE
        // so a forgotten override doesn't poison the next test.
        Route::post('queue-mode', [SetQueueModeController::class, 'store'])
            ->name('queue_mode.set');
        Route::delete('queue-mode', [SetQueueModeController::class, 'destroy'])
            ->name('queue_mode.clear');
    });
