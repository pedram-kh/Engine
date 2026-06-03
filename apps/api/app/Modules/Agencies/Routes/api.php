<?php

declare(strict_types=1);

use App\Modules\Agencies\Http\Controllers\AgencyCreatorAvailabilityController;
use App\Modules\Agencies\Http\Controllers\AgencyCreatorController;
use App\Modules\Agencies\Http\Controllers\AgencySettingsController;
use App\Modules\Agencies\Http\Controllers\DashboardActivityController;
use App\Modules\Agencies\Http\Controllers\DashboardSummaryController;
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

        // ─── Dashboard (workspace home) ──────────────────────────────────────
        // Sprint 4 Chunk 1. Any agency member may read the dashboard; no MFA
        // gate (matches the `/` route). `summary` returns the four KPI values
        // in one payload (1b); `activity` returns the agency-scoped audit feed
        // (1c).
        Route::get('dashboard/summary', DashboardSummaryController::class)
            ->name('agencies.dashboard.summary');
        Route::get('dashboard/activity', DashboardActivityController::class)
            ->name('agencies.dashboard.activity');

        // ─── Creator roster ("my creators") ──────────────────────────────────
        // Sprint 4 Chunk 5 (D-c5-1). Any agency member may view the roster
        // (gated by AgencyCreatorRelationPolicy::viewAny inside the
        // controller). Lists the agency's relations across ALL
        // relationship_status values, joined to their creators, with the
        // status / country / language / category filters that have backing
        // data today. Read-only: no write surface this chunk (D-c5-3).
        Route::get('creators', [AgencyCreatorController::class, 'index'])
            ->name('agencies.creators.index');

        // ─── Creator availability read-view ──────────────────────────────────
        // Sprint 5 Chunk A (D-a6). Agency-side read of a ROSTER creator's
        // expanded availability — `reason` omitted (creator-only, B4) via the
        // dedicated AgencyAvailabilityResource. Built standalone now (plan-pause
        // B6); Sprint 6's creator-detail page consumes it. Scope: the creator
        // must have an AgencyCreatorRelation with this agency (any status),
        // else 404. Path-scoped under tenancy.agency + tenancy — no §4
        // allowlist entry needed (it is inside the tenancy stack).
        Route::get('creators/{creator}/availability', [AgencyCreatorAvailabilityController::class, 'show'])
            ->name('agencies.creators.availability.show');
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
