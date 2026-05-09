<?php

declare(strict_types=1);

use App\TestHelpers\Http\Controllers\IssueTotpController;
use App\TestHelpers\Http\Controllers\MintVerificationTokenController;
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

        Route::post('clock', SetClockController::class)
            ->name('clock.set');

        Route::post('clock/reset', ResetClockController::class)
            ->name('clock.reset');
    });
