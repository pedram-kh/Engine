<?php

declare(strict_types=1);

use App\Modules\Agencies\Http\Controllers\AgencyConnectionRequestController;
use App\Modules\Agencies\Http\Controllers\AgencyCreatorAvailabilityController;
use App\Modules\Agencies\Http\Controllers\AgencyCreatorController;
use App\Modules\Agencies\Http\Controllers\AgencyCreatorDetailController;
use App\Modules\Agencies\Http\Controllers\AgencyCreatorDiscoveryController;
use App\Modules\Agencies\Http\Controllers\AgencySettingsController;
use App\Modules\Agencies\Http\Controllers\CreatorBlacklistController;
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

        // ─── Creator discovery (the global pool) ──────────────────────────────
        // Sprint 6.6a (D-1). The FIRST agency-facing creator query that is NOT
        // relation-scoped: it browses/searches the GLOBAL `creators` pool with
        // the fail-closed discoverable gate (approved + is_discoverable), the
        // shared FTS/filter logic (D-3), and the calling-agency-scoped
        // "already-connected" annotation (D-4/D-7). `discover` ability, any
        // member. Read-only — no send-request action (D-9, that's 6.6b).
        //
        // ⚠ Registration order: these LITERAL `creators/discover*` routes MUST
        // precede the `creators/{creator}` param route below, or `discover`
        // would be captured as a {creator} ULID. `index` (creators/discover)
        // collides with creators/{creator} on segment count; the show route
        // (creators/discover/{creator}) does not, but is kept here for clarity.
        Route::get('creators/discover', [AgencyCreatorDiscoveryController::class, 'index'])
            ->name('agencies.creators.discover.index');
        Route::get('creators/discover/{creator}', [AgencyCreatorDiscoveryController::class, 'show'])
            ->name('agencies.creators.discover.show');

        // ─── Send connection request (the AGENCY half of the lifecycle) ───────
        // Sprint 6.6b (D-7). A STATEFUL WRITE off the discovery surface, in its
        // own controller (NOT the read-only discovery controller): admin/manager
        // only (sendRequest ability — staff 403), same fail-closed discoverable
        // gate as the reads, and it fires ConnectionRequestMail (D-9). Creates
        // the relation in `pending_request` (no magic-link token/expiry) from
        // `(none)`, re-requests from `declined` (D-4), and no-ops surfacing the
        // existing state for any other status. Path-scoped under tenancy.agency
        // + tenancy — no §4 allowlist entry needed.
        Route::post('creators/discover/{creator}/connection-request', [AgencyConnectionRequestController::class, 'store'])
            ->name('agencies.creators.discover.connection-request');

        // ─── Creator detail (per-creator drill-in) ───────────────────────────
        // Sprint 6 Chunk 2a (D-2a-1). The roster row-click (D-c5-4 reversal)
        // lands on `show`. READ is any agency member (viewAny); PATCH edits
        // the relation's rating + notes ONLY and is admin/manager-gated
        // (AgencyCreatorRelationPolicy::update, D-2a-3/4). Tenancy mirrors the
        // roster + availability controllers: a relation (any status) must
        // exist between this agency and creator, else 404. Path-scoped under
        // tenancy.agency + tenancy. The platform_admin admin detail endpoint
        // is untouched (D-2a-1 does not relax it).
        Route::get('creators/{creator}', [AgencyCreatorDetailController::class, 'show'])
            ->name('agencies.creators.show');
        Route::patch('creators/{creator}', [AgencyCreatorDetailController::class, 'update'])
            ->name('agencies.creators.update');

        // ─── Creator blacklist (Sprint 7, Part A) ─────────────────────────────
        // A DEDICATED write surface (NOT the rating/notes PATCH — D-2 forbids a
        // dual-write). Admin/manager only (`blacklist` ability — staff 403).
        // `scope` in the body chooses the path: agency-wide (columns ON the
        // relation, requires an existing relation) or brand-scoped (a
        // brand_creator_blacklists row, no relation touch). Path-scoped under
        // tenancy.agency + tenancy — no §4 allowlist entry needed.
        Route::post('creators/{creator}/blacklist', [CreatorBlacklistController::class, 'store'])
            ->name('agencies.creators.blacklist.store');
        Route::delete('creators/{creator}/blacklist', [CreatorBlacklistController::class, 'destroy'])
            ->name('agencies.creators.blacklist.destroy');

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
