<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Controllers\ConfirmTwoFactorController;
use App\Modules\Identity\Http\Controllers\DisableTwoFactorController;
use App\Modules\Identity\Http\Controllers\EnableTwoFactorController;
use App\Modules\Identity\Http\Controllers\LoginController;
use App\Modules\Identity\Http\Controllers\LogoutController;
use App\Modules\Identity\Http\Controllers\MeController;
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
// Cross-cutting authenticated endpoints — main SPA
// ---------------------------------------------------------------------------
//
// `/me` is mounted at the v1 root rather than under `auth/` because it
// is a "who am I?" lookup the SPA fires on every cold load, not part of
// the credential-exchange surface. The route stays inside the API
// stateful group (so cookie + CSRF resolution apply identically to the
// auth endpoints) and additionally mounts `tenancy.set` to populate the
// TenancyContext from the user's primary AgencyMembership. For
// creators / platform admins the populator is a no-op; this is the
// documented behaviour from docs/security/tenancy.md.
//
// The fail-closed `tenancy` alias is intentionally NOT applied — creators
// and platform-admin users have no agency context, and `tenancy` would
// 500 every /me request for those user types. The standard three-
// middleware stack from docs/security/tenancy.md § 3 (`auth:web` +
// `tenancy.set` + `tenancy`) applies only when the route both reads
// tenant-scoped data AND is reachable only by agency users.

Route::get('me', MeController::class)
    ->middleware(['auth:web', 'tenancy.set'])
    ->name('me');

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
// Cross-cutting authenticated endpoints — admin SPA
// ---------------------------------------------------------------------------
//
// `/admin/me` mirrors the main SPA's `/me` but lives under `admin/` so
// the path-aware UseAdminSessionCookie middleware swaps the session
// cookie name before StartSession runs. EnsureMfaForAdmins is mounted
// per chunk 5 priority #7: an admin who has not enrolled 2FA gets a
// 403 envelope with `auth.mfa.enrollment_required` here, which the
// admin SPA uses as the signal to redirect to /auth/2fa/enable.

Route::get('admin/me', MeController::class)
    ->middleware(['auth:web_admin', EnsureMfaForAdmins::class, 'tenancy.set'])
    ->name('admin.me');

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
