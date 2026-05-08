<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Controllers\LoginController;
use App\Modules\Identity\Http\Controllers\LogoutController;
use App\Modules\Identity\Http\Controllers\PasswordResetController;
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
    });
