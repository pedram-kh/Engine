<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Controllers\ConfirmTwoFactorController;
use App\Modules\Identity\Http\Controllers\DisableTwoFactorController;
use App\Modules\Identity\Http\Controllers\EnableTwoFactorController;
use App\Modules\Identity\Http\Controllers\LoginController;
use App\Modules\Identity\Http\Controllers\LogoutController;
use App\Modules\Identity\Http\Controllers\PasswordResetController;
use App\Modules\Identity\Http\Controllers\RegenerateRecoveryCodesController;
use App\Modules\Identity\Http\Controllers\ResendVerificationController;
use App\Modules\Identity\Http\Controllers\SignUpController;
use App\Modules\Identity\Http\Controllers\VerifyEmailController;
use App\Modules\Identity\Http\Middleware\EnsureMfaForAdmins;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Identity module routes
|--------------------------------------------------------------------------
|
| Mounted by IdentityServiceProvider under the 'api' middleware group with
| prefix '/api/v1'. Both groups below run inside the Sanctum-stateful API
| stack ($middleware->statefulApi() in bootstrap/app.php), so requests from
| a SANCTUM_STATEFUL_DOMAINS origin transparently get session + CSRF.
|
| Throttling matches docs/04-API-DESIGN.md §13:
|   - Unauthenticated auth endpoints: 10/min/IP.
|   - The login endpoint additionally enforces 5/min/email — applied as a
|     keyed throttler bound to the lower-cased email + IP, see the inline
|     middleware below.
|
*/

// ---------------------------------------------------------------------------
// Main SPA — guard 'web', cookie 'catalyst_main_session'
// ---------------------------------------------------------------------------

Route::prefix('auth')
    ->name('auth.')
    ->middleware('throttle:auth-ip')
    ->group(function (): void {
        Route::post('login', LoginController::class)
            ->middleware('throttle:auth-login-email')
            ->name('login');

        Route::post('logout', LogoutController::class)
            ->middleware('auth:web')
            ->name('logout');

        Route::post('forgot-password', [PasswordResetController::class, 'forgot'])
            ->middleware('throttle:auth-password')
            ->name('password.forgot');

        Route::post('reset-password', [PasswordResetController::class, 'reset'])
            ->middleware('throttle:auth-password')
            ->name('password.reset');

        Route::post('sign-up', SignUpController::class)
            ->name('signup');

        Route::post('verify-email', VerifyEmailController::class)
            ->name('email.verify');

        Route::post('resend-verification', ResendVerificationController::class)
            ->middleware('throttle:auth-resend-verification')
            ->name('email.resend');

        // 2FA enrollment + management. All endpoints require an
        // authenticated session on the main guard. The `confirm`
        // endpoint can be reached repeatedly until it succeeds, hence
        // the dedicated per-user verification throttle inside the
        // service rather than a route-level limiter (an honest user
        // who fat-fingers four codes still gets a fifth try).
        Route::post('2fa/enable', EnableTwoFactorController::class)
            ->middleware('auth:web')
            ->name('2fa.enable');

        Route::post('2fa/confirm', ConfirmTwoFactorController::class)
            ->middleware('auth:web')
            ->name('2fa.confirm');

        Route::post('2fa/disable', DisableTwoFactorController::class)
            ->middleware('auth:web')
            ->name('2fa.disable');

        Route::post('2fa/recovery-codes', RegenerateRecoveryCodesController::class)
            ->middleware('auth:web')
            ->name('2fa.recovery_codes');
    });

// ---------------------------------------------------------------------------
// Admin SPA — guard 'web_admin', cookie 'catalyst_admin_session'
// (set by the global UseAdminSessionCookie middleware on every
// `api/v1/admin/*` request before StartSession runs).
// ---------------------------------------------------------------------------

Route::prefix('admin/auth')
    ->name('admin.auth.')
    ->middleware('throttle:auth-ip')
    ->group(function (): void {
        Route::post('login', LoginController::class)
            ->middleware('throttle:auth-login-email')
            ->name('login');

        Route::post('logout', LogoutController::class)
            ->middleware('auth:web_admin')
            ->name('logout');

        // 2FA enrollment endpoints on the admin guard. These are NOT
        // protected by EnsureMfaForAdmins because they ARE the
        // enrollment path — gating them behind MFA would be a
        // chicken-and-egg lockout. Every other admin route registered
        // by other modules MUST mount EnsureMfaForAdmins after the
        // auth guard (chunk 5 priority #7).
        Route::post('2fa/enable', EnableTwoFactorController::class)
            ->middleware('auth:web_admin')
            ->name('2fa.enable');

        Route::post('2fa/confirm', ConfirmTwoFactorController::class)
            ->middleware('auth:web_admin')
            ->name('2fa.confirm');

        Route::post('2fa/disable', DisableTwoFactorController::class)
            ->middleware(['auth:web_admin', EnsureMfaForAdmins::class])
            ->name('2fa.disable');

        Route::post('2fa/recovery-codes', RegenerateRecoveryCodesController::class)
            ->middleware(['auth:web_admin', EnsureMfaForAdmins::class])
            ->name('2fa.recovery_codes');
    });
