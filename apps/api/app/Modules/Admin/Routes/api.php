<?php

declare(strict_types=1);

use App\Modules\Admin\Http\Controllers\AdminAgencyController;
use App\Modules\Admin\Http\Controllers\AdminAlertsController;
use App\Modules\Admin\Http\Controllers\AdminAuditLogController;
use App\Modules\Admin\Http\Controllers\AdminComplianceController;
use App\Modules\Admin\Http\Controllers\AdminDashboardController;
use App\Modules\Admin\Http\Controllers\AdminFeatureFlagController;
use App\Modules\Admin\Http\Controllers\AdminHealthController;
use App\Modules\Admin\Http\Controllers\AdminImpersonationController;
use App\Modules\Identity\Http\Middleware\EnsureMfaForAdmins;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin module routes (Sprint 13 — Admin panel core)
|--------------------------------------------------------------------------
|
| Mounted by AdminServiceProvider under the 'api' middleware group with
| prefix '/api/v1'. Every route here is a platform-admin surface:
|
|   - Guard:      auth:web_admin (the admin SPA session cookie, set by the
|                 path-aware UseAdminSessionCookie middleware mounted
|                 globally — session isolation from the main SPA's `web`
|                 guard, the two-cookie model).
|   - MFA gate:   EnsureMfaForAdmins (chunk-5 priority #7 — an admin who
|                 has not enrolled 2FA receives auth.mfa.enrollment_required
|                 on every route).
|   - Tenancy:    tenant-less by category. platform_admin users have no
|                 agency membership and admin surfaces are cross-agency BY
|                 DESIGN (the bounded bypass — docs/security/tenancy.md § 4).
|                 The fail-closed `tenancy` alias is NEVER mounted here (it
|                 would 500 every admin request); `tenancy.set` is omitted
|                 because admin reads use explicit withoutGlobalScope /
|                 tenant-less queries (the AdminCreatorController pattern).
|
| NOTE: the pre-existing admin Creator drill-in routes live in the
| Creators module's Routes/api.php (Sprint 3-4) — they predate this
| module being built out. Net-new Sprint-13 admin routes land HERE.
|
| All new admin routes are added to docs/security/tenancy.md § 4
| (cross-tenant allowlist) as they ship.
|
*/

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth:web_admin', EnsureMfaForAdmins::class])
    ->group(function (): void {
        // ─── Agencies (D-3) ──────────────────────────────────────────
        // Cross-agency admin tooling: list / detail / suspend / reactivate.
        // Suspend cuts off every agency user's login (enforced in
        // AuthService::login). Allowlisted in docs/security/tenancy.md § 4.
        Route::prefix('agencies')->name('agencies.')->group(function (): void {
            Route::get('/', [AdminAgencyController::class, 'index'])->name('index');
            Route::get('{agency}', [AdminAgencyController::class, 'show'])->name('show');
            Route::post('{agency}/suspend', [AdminAgencyController::class, 'suspend'])->name('suspend');
            Route::post('{agency}/reactivate', [AdminAgencyController::class, 'reactivate'])->name('reactivate');
        });

        // ─── Audit-log viewer (D-5) ──────────────────────────────────
        // Read-only, cross-agency, cursor-paginated view over the
        // append-only audit_logs table. All filters hit indexed columns.
        Route::get('audit-logs', [AdminAuditLogController::class, 'index'])->name('audit-logs.index');

        // ─── Feature-flag toggles (D-6) ──────────────────────────────
        // The RUNTIME mutation path over DB-backed Pennant flags. Every
        // flip writes a feature_flag.toggled audit row (reason required).
        // Platform-level on/off only (per-tenant overrides are tech-debt).
        Route::prefix('feature-flags')->name('feature-flags.')->group(function (): void {
            Route::get('/', [AdminFeatureFlagController::class, 'index'])->name('index');
            Route::post('{flag}', [AdminFeatureFlagController::class, 'toggle'])->name('toggle');
        });

        // ─── Dashboard (D-7) ─────────────────────────────────────────
        // Non-payment operational KPIs + the recent audit activity feed.
        // Payment/dispute cards are stable null placeholders (D-13).
        Route::prefix('dashboard')->name('dashboard.')->group(function (): void {
            Route::get('summary', [AdminDashboardController::class, 'summary'])->name('summary');
            Route::get('activity', [AdminDashboardController::class, 'activity'])->name('activity');
        });

        // ─── Operations system-health probe (D-8) ────────────────────
        // Cheap liveness check over DB + cache. Queues / failed jobs are
        // the gated Horizon embed (a nav link out, not a SPA route).
        Route::get('health', [AdminHealthController::class, 'index'])->name('health');

        // ─── Impersonation — admin side (D-9) ────────────────────────
        // Start mints a one-time hand-off token (the two-cookie bridge);
        // the impersonated session is established on the MAIN SPA via the
        // /auth/impersonation/claim endpoint. The admin's own session is
        // never destroyed. Every start carries a mandatory reason.
        Route::prefix('impersonate')->name('impersonate.')->group(function (): void {
            Route::get('users', [AdminImpersonationController::class, 'searchUsers'])->name('users');
            Route::get('sessions', [AdminImpersonationController::class, 'sessions'])->name('sessions');
            Route::post('/', [AdminImpersonationController::class, 'start'])->name('start');
            Route::post('end', [AdminImpersonationController::class, 'end'])->name('end');
        });

        // ─── GDPR compliance queues — SHELLS (D-11) ──────────────────
        // Data-subject export (art. 15/20) + erasure (art. 17) operator
        // surfaces. Ship this sprint as empty shells: each returns 200 with
        // `data: []` + `meta.shell: true` (NOT 404 — the feature exists, it
        // simply has no backing store until S14 lands the
        // data_export_requests / data_erasure_requests tables). Read-only.
        Route::prefix('compliance')->name('compliance.')->group(function (): void {
            Route::get('export-requests', [AdminComplianceController::class, 'exports'])->name('exports');
            Route::get('erasure-queue', [AdminComplianceController::class, 'erasures'])->name('erasures');
        });

        // ─── Operational alerts — admin notification consumer (D-12) ──
        // The non-payment admin notification surface: the admin's own
        // operational alerts feed, reusing the S11.0 notification subsystem
        // (a platform_admin is a User). Payment-event alerts are held back
        // (coming-soon, D-13) and surfaced as a discrete meta block. Shell:
        // ships empty until the operational emit sites land.
        Route::get('alerts', [AdminAlertsController::class, 'index'])->name('alerts.index');
    });
