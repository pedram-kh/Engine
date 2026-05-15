<?php

declare(strict_types=1);

use App\Modules\Agencies\Http\Controllers\AgencySettingsController;
use App\Modules\Agencies\Http\Controllers\InvitationController;
use App\Modules\Agencies\Http\Controllers\InvitationPreviewController;
use App\Modules\Agencies\Http\Controllers\MembershipController;
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

        // ─── Invitations ─────────────────────────────────────────────────────
        // Creation (admin only — enforced inline in the controller).
        Route::post('invitations', [InvitationController::class, 'store'])
            ->name('agencies.invitations.store');

        // Sprint 3 Chunk 4 sub-step 3 — paginated history listing.
        // Admin-only (enforced inline). Path-scoped via the tenancy.agency
        // middleware; no allowlist entry needed.
        Route::get('invitations', [InvitationController::class, 'index'])
            ->name('agencies.invitations.index');

        // ─── Members (paginated listing) ─────────────────────────────────────
        // Sprint 3 Chunk 4 sub-step 3. Any agency member may list members;
        // the Manage actions on the agency-users page are admin-gated in
        // the UI layer.
        Route::get('members', [MembershipController::class, 'index'])
            ->name('agencies.members.index');
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
