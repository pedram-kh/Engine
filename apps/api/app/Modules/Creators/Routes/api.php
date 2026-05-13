<?php

declare(strict_types=1);

use App\Modules\Creators\Http\Controllers\AvatarController;
use App\Modules\Creators\Http\Controllers\BulkInviteController;
use App\Modules\Creators\Http\Controllers\CreatorWizardController;
use App\Modules\Creators\Http\Controllers\InvitationPreviewController;
use App\Modules\Creators\Http\Controllers\PortfolioController;
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
    ->middleware(['auth:web', 'tenancy.set'])
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
            Route::post('submit', [CreatorWizardController::class, 'submit'])
                ->name('submit');
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
