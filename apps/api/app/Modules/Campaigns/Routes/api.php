<?php

declare(strict_types=1);

use App\Modules\Campaigns\Http\Controllers\CampaignAssignmentContractController;
use App\Modules\Campaigns\Http\Controllers\CampaignAssignmentController;
use App\Modules\Campaigns\Http\Controllers\CampaignAssignmentReviewController;
use App\Modules\Campaigns\Http\Controllers\CampaignController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Campaigns module routes
|--------------------------------------------------------------------------
|
| All routes are tenant-scoped to an agency. The middleware stack:
|   - auth:web       — requires authenticated session
|   - tenancy.agency — resolves {agency} binding, verifies membership, sets context
|   - tenancy        — fail-closed guard: 500 if context missing
|
| See docs/security/tenancy.md §3. Campaign create + Settings edit are
| admin/manager-gated inside the controller (Gate::authorize); any member may
| view (D-10). Inviting (D-3) + re-inviting (D-7) are gated on the broader
| `invite` execute ability (admin + manager + staff, D-6).
|
*/

Route::middleware(['auth:web', 'tenancy.agency', 'tenancy'])
    ->prefix('agencies/{agency}')
    ->group(function (): void {
        Route::get('campaigns', [CampaignController::class, 'index'])
            ->name('campaigns.index');
        Route::post('campaigns', [CampaignController::class, 'store'])
            ->name('campaigns.store');
        Route::get('campaigns/{campaign}', [CampaignController::class, 'show'])
            ->name('campaigns.show');
        Route::patch('campaigns/{campaign}', [CampaignController::class, 'update'])
            ->name('campaigns.update');

        // Read-only assignment listing for the Creators tab (Chunk 1).
        Route::get('campaigns/{campaign}/assignments', [CampaignAssignmentController::class, 'index'])
            ->name('campaigns.assignments.index');

        // Invite a creator (Chunk 2, D-3) — the two-tier gate + execute ability.
        Route::post('campaigns/{campaign}/assignments', [CampaignAssignmentController::class, 'store'])
            ->name('campaigns.assignments.store');

        // Re-invite after a counter (Chunk 2, D-7) — the guarded machine edge.
        Route::post('campaigns/{campaign}/assignments/{assignment}/reinvite', [CampaignAssignmentController::class, 'reinvite'])
            ->name('campaigns.assignments.reinvite');

        // Agency-side draft review (Sprint 9 Chunk 2). The drawer read (D-7) +
        // the three per-action review endpoints (D-4), gated on the `review`
        // ability (admin + manager + staff, D-6).
        Route::get('campaigns/{campaign}/assignments/{assignment}', [CampaignAssignmentReviewController::class, 'show'])
            ->name('campaigns.assignments.show');
        Route::post('campaigns/{campaign}/assignments/{assignment}/approve', [CampaignAssignmentReviewController::class, 'approve'])
            ->name('campaigns.assignments.approve');
        Route::post('campaigns/{campaign}/assignments/{assignment}/request-revision', [CampaignAssignmentReviewController::class, 'requestRevision'])
            ->name('campaigns.assignments.request-revision');
        Route::post('campaigns/{campaign}/assignments/{assignment}/reject', [CampaignAssignmentReviewController::class, 'reject'])
            ->name('campaigns.assignments.reject');

        // Per-campaign contract attach (contract-bridge chunk, D-6/D-9). Agency
        // issues a contract to an accepted assignment; creator accept is on
        // creators/me/assignments/{assignment}/contract/accept.
        Route::post('campaigns/{campaign}/assignments/{assignment}/contract/media/init', [CampaignAssignmentContractController::class, 'initMedia'])
            ->name('campaigns.assignments.contract.media.init');
        Route::post('campaigns/{campaign}/assignments/{assignment}/contract/media/complete', [CampaignAssignmentContractController::class, 'completeMedia'])
            ->name('campaigns.assignments.contract.media.complete');
        Route::post('campaigns/{campaign}/assignments/{assignment}/contract/attach', [CampaignAssignmentContractController::class, 'attach'])
            ->name('campaigns.assignments.contract.attach');
    });
