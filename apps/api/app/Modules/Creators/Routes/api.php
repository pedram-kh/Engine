<?php

declare(strict_types=1);

use App\Modules\Creators\Http\Controllers\Admin\AdminCreatorController;
use App\Modules\Creators\Http\Controllers\AvatarController;
use App\Modules\Creators\Http\Controllers\BulkInviteController;
use App\Modules\Creators\Http\Controllers\CreatorWizardController;
use App\Modules\Creators\Http\Controllers\InvitationPreviewController;
use App\Modules\Creators\Http\Controllers\PortfolioController;
use App\Modules\Creators\Http\Controllers\Webhooks\EsignWebhookController;
use App\Modules\Creators\Http\Controllers\Webhooks\KycWebhookController;
use App\Modules\Creators\Http\Controllers\WizardCompletionController;
use App\Modules\Identity\Http\Middleware\EnsureMfaForAdmins;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Creators module routes
|--------------------------------------------------------------------------
|
| Mounted by CreatorsServiceProvider under the 'api' middleware group with
| prefix '/api/v1'. All routes here are creator-scoped (the authenticated
| user's own Creator row), so they sit OUTSIDE any tenancy.* middleware
| stack — Creator is a global entity (docs/03-DATA-MODEL.md § 5).
|
| The fail-closed `tenancy` alias is intentionally NOT applied; `tenancy.set`
| IS applied so that if a creator user happens to also belong to an agency
| (Sprint 4+), the TenancyContext is still populated for downstream use.
|
| The `verified` middleware (Laravel's EnsureEmailIsVerified) was added in
| Sprint 3 Chunk 2 sub-step 1 alongside the PasswordResetService::request()
| email_verified_at gate as defence-in-depth (#40) against the bulk-invite
| throwaway-password vector. See docs/reviews/sprint-3-chunk-1-review.md
| "P1 blockers for Chunk 2" and the chunk-2 review's sub-step 1.
|
| Cross-tenant route allowlist (docs/security/tenancy.md § 4):
|   GET    /api/v1/creators/me                    Sprint 3 Chunk 1
|   PATCH  /api/v1/creators/me/wizard/profile     Sprint 3 Chunk 1
|   POST   /api/v1/creators/me/wizard/social      Sprint 3 Chunk 1
|   POST   /api/v1/creators/me/wizard/kyc         Sprint 3 Chunk 1
|   PATCH  /api/v1/creators/me/wizard/tax         Sprint 3 Chunk 1
|   POST   /api/v1/creators/me/wizard/payout      Sprint 3 Chunk 1
|   POST   /api/v1/creators/me/wizard/contract    Sprint 3 Chunk 1
|   POST   /api/v1/creators/me/wizard/submit      Sprint 3 Chunk 1
|   POST   /api/v1/creators/me/avatar             Sprint 3 Chunk 1
|   DELETE /api/v1/creators/me/avatar             Sprint 3 Chunk 1
|   POST   /api/v1/creators/me/portfolio/...      Sprint 3 Chunk 1
|   DELETE /api/v1/creators/me/portfolio/{item}   Sprint 3 Chunk 1
|
*/

Route::prefix('creators/me')
    ->name('creators.me.')
    ->middleware(['auth:web', 'tenancy.set', 'verified'])
    ->group(function (): void {
        Route::get('/', [CreatorWizardController::class, 'show'])->name('show');

        Route::prefix('wizard')->name('wizard.')->group(function (): void {
            Route::patch('profile', [CreatorWizardController::class, 'updateProfile'])
                ->name('profile.update');
            Route::post('social', [CreatorWizardController::class, 'connectSocial'])
                ->name('social.connect');
            Route::post('kyc', [CreatorWizardController::class, 'initiateKyc'])
                ->name('kyc.initiate');
            Route::patch('tax', [CreatorWizardController::class, 'upsertTaxProfile'])
                ->name('tax.update');
            Route::post('payout', [CreatorWizardController::class, 'initiatePayout'])
                ->name('payout.initiate');
            Route::post('contract', [CreatorWizardController::class, 'initiateContract'])
                ->name('contract.initiate');
            Route::get('contract/terms', [CreatorWizardController::class, 'getContractTerms'])
                ->name('contract.terms');
            Route::post('contract/click-through-accept', [CreatorWizardController::class, 'clickThroughAcceptContract'])
                ->name('contract.click-through-accept');
            Route::post('submit', [CreatorWizardController::class, 'submit'])
                ->name('submit');

            // Sprint 3 Chunk 2 sub-step 6 — status-poll + return
            // endpoints for the three vendor-gated steps. Status
            // is the periodic poll while the creator stays on the
            // wizard; return is the redirect-bounce target the
            // mock-vendor (or real vendor) lands them on after
            // completion. Both call WizardCompletionService and
            // emit the matching creator.wizard.*_completed audit
            // on the success edge (idempotent on re-poll, #6).
            Route::get('kyc/status', [WizardCompletionController::class, 'kycStatus'])
                ->name('kyc.status');
            Route::get('kyc/return', [WizardCompletionController::class, 'kycReturn'])
                ->name('kyc.return');
            Route::get('contract/status', [WizardCompletionController::class, 'contractStatus'])
                ->name('contract.status');
            Route::get('contract/return', [WizardCompletionController::class, 'contractReturn'])
                ->name('contract.return');
            Route::get('payout/status', [WizardCompletionController::class, 'payoutStatus'])
                ->name('payout.status');
            Route::get('payout/return', [WizardCompletionController::class, 'payoutReturn'])
                ->name('payout.return');
        });

        Route::post('avatar', [AvatarController::class, 'store'])->name('avatar.store');
        Route::delete('avatar', [AvatarController::class, 'destroy'])->name('avatar.destroy');

        Route::prefix('portfolio')->name('portfolio.')->group(function (): void {
            Route::post('images', [PortfolioController::class, 'uploadImage'])
                ->name('image.store');
            Route::post('videos/init', [PortfolioController::class, 'initiateVideoUpload'])
                ->name('video.init');
            Route::post('videos/complete', [PortfolioController::class, 'completeVideoUpload'])
                ->name('video.complete');
            Route::delete('{portfolioItem}', [PortfolioController::class, 'destroy'])
                ->name('destroy');
        });
    });

// ---------------------------------------------------------------------------
// Bulk creator invitation — agency-scoped
// ---------------------------------------------------------------------------
//
// Per docs/security/tenancy.md § 4 this route is tenant-scoped via the
// {agency} path parameter; the controller resolves membership +
// agency_admin role inline (D-pause-9, mirrors Sprint 2 pattern).
//
// `/agencies/{agency}` group is NOT under tenancy.* middleware here
// because the route lives in the Creators module; the controller's
// authorizeAdmin() bypasses BelongsToAgencyScope on the membership
// lookup and enforces the role check directly.

Route::prefix('agencies/{agency}')
    ->middleware('auth:web')
    ->group(function (): void {
        Route::post('creators/invitations/bulk', [BulkInviteController::class, 'store'])
            ->name('agencies.creators.invitations.bulk');
    });

// ---------------------------------------------------------------------------
// Public invitation preview — unauthenticated
// ---------------------------------------------------------------------------
//
// Pushback (kickoff Refinements §2): response shape is
// {agency_name, is_expired, is_accepted} only — no email exposure.
// Standing standard #42 applied. Generic 404 on token-not-found.
//
// Mounted at the top level so the magic-link landing page can hit it
// without an active session. NOT in the cross-tenant allowlist because
// it returns no tenant-bearing data.

Route::get('creators/invitations/preview', InvitationPreviewController::class)
    ->name('creators.invitations.preview');

// ---------------------------------------------------------------------------
// Admin SPA — Creator drill-in (Sprint 3 Chunk 3 + Chunk 4)
// ---------------------------------------------------------------------------
//
// Paths:
//   GET    /api/v1/admin/creators/{creator}          — Sprint 3 Chunk 3
//   PATCH  /api/v1/admin/creators/{creator}          — Sprint 3 Chunk 4
//   POST   /api/v1/admin/creators/{creator}/approve  — Sprint 3 Chunk 4
//   POST   /api/v1/admin/creators/{creator}/reject   — Sprint 3 Chunk 4
//
// Authentication: 'auth:web_admin' (admin SPA session cookie, gated by
// the path-aware UseAdminSessionCookie middleware mounted globally).
// EnsureMfaForAdmins gates every route per chunk 5 priority #7 — admins
// who haven't enrolled 2FA receive auth.mfa.enrollment_required.
//
// Tenancy: tenant-less by category — Creator is a global entity
// (docs/03-DATA-MODEL.md § 5) and `platform_admin` users do not have
// agency membership. The fail-closed `tenancy` alias is NOT mounted
// (it would 500 every admin request); `tenancy.set` is also omitted
// because the controller does not query any agency-scoped models.
//
// Allowlisted in docs/security/tenancy.md § 4 (sub-step 12 fix-up):
//   GET    /api/v1/admin/creators/{creator}          — path-scoped admin tooling
//   PATCH  /api/v1/admin/creators/{creator}          — path-scoped admin tooling
//   POST   /api/v1/admin/creators/{creator}/approve  — path-scoped admin tooling
//   POST   /api/v1/admin/creators/{creator}/reject   — path-scoped admin tooling
//
// Authorization:
//   GET    → CreatorPolicy::view       (Chunk 1; platform_admin branch).
//   PATCH  → CreatorPolicy::adminUpdate (Chunk 1; platform_admin branch).
//   approve → CreatorPolicy::approve   (Chunk 4; replaces Chunk 1 stub).
//   reject  → CreatorPolicy::reject    (Chunk 4; replaces Chunk 1 stub).

Route::prefix('admin/creators')
    ->name('admin.creators.')
    ->middleware(['auth:web_admin', EnsureMfaForAdmins::class])
    ->group(function (): void {
        Route::get('{creator}', [AdminCreatorController::class, 'show'])
            ->name('show');
        Route::patch('{creator}', [AdminCreatorController::class, 'update'])
            ->name('update');
        Route::post('{creator}/approve', [AdminCreatorController::class, 'approve'])
            ->name('approve');
        Route::post('{creator}/reject', [AdminCreatorController::class, 'reject'])
            ->name('reject');
    });

// ---------------------------------------------------------------------------
// Inbound vendor webhooks — tenant-less + unauthenticated
// ---------------------------------------------------------------------------
//
// Sprint 3 Chunk 2 sub-step 7 + docs/06-INTEGRATIONS.md § 1.3.
// Vendors POST signed payloads from their own infrastructure. The
// controller verifies the signature inline and returns 401 with a
// single `integration.webhook.signature_failed` error code on
// failure (no granular failure-mode codes per the chunk-2 plan's
// "Decisions documented for future chunks").
//
// Tenant-less by design: the request has no session / auth, and
// the payload's contents (creator_ulid) drive the downstream
// state update inside the Process*WebhookJob — not the route layer.
// Allowlisted in docs/security/tenancy.md § 4 (sub-step 11).
//
// Rate limit: 1000 req/min per provider via the `webhooks` named
// limiter registered in CreatorsServiceProvider::boot(). Stripe
// Connect's webhook handler is deferred to Sprint 10 per
// Q-stripe-no-webhook-acceptable.

Route::middleware('throttle:webhooks')
    ->prefix('webhooks')
    ->name('webhooks.')
    ->group(function (): void {
        Route::post('kyc', KycWebhookController::class)->name('kyc');
        Route::post('esign', EsignWebhookController::class)->name('esign');
    });
