<?php

declare(strict_types=1);

use App\Modules\Agencies\Http\Controllers\AgencySettingsController;
use App\Modules\Agencies\Http\Controllers\InvitationController;
use App\Modules\Agencies\Http\Controllers\InvitationPreviewController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Agencies module routes
|--------------------------------------------------------------------------
|
| Tenant-scoped routes require the tenancy middleware stack:
|   auth:web → tenancy.agency → tenancy
|
| The accept invitation route uses auth:web only (accepting user is not
| yet a member; tenancy.agency would reject them).
|
*/

// ─── Agency settings ──────────────────────────────────────────────────────────
// Both GET and PATCH require the user to be a member (tenancy.agency).
// PATCH additionally requires agency_admin role (enforced inside the controller).

Route::middleware(['auth:web', 'tenancy.agency', 'tenancy'])
    ->prefix('agencies/{agency}')
    ->group(function (): void {
        Route::get('settings', [AgencySettingsController::class, 'show'])
            ->name('agencies.settings.show');

        Route::patch('settings', [AgencySettingsController::class, 'update'])
            ->name('agencies.settings.update');

        // ─── Invitations (creation — admin only) ─────────────────────────────
        Route::post('invitations', [InvitationController::class, 'store'])
            ->name('agencies.invitations.store');
    });

// ─── Accept invitation ────────────────────────────────────────────────────────
// The accepting user is authenticated but NOT yet a member of the agency,
// so tenancy.agency would reject them. We use auth:web only and verify
// the email match inside the controller.

Route::middleware(['auth:web'])
    ->prefix('agencies/{agency}')
    ->group(function (): void {
        Route::post('invitations/accept', [InvitationController::class, 'accept'])
            ->name('agencies.invitations.accept');
    });

// ─── Invitation preview (no auth) ─────────────────────────────────────────────
// Unauthenticated — the invitee may not have an account yet. Returns
// invitation metadata so the SPA accept page can show "Joining X as Y"
// before the user signs in. Token is passed as ?token=<unhashed>.

Route::prefix('agencies/{agency}')
    ->group(function (): void {
        Route::get('invitations/preview', InvitationPreviewController::class)
            ->name('agencies.invitations.preview');
    });
