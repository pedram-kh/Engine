<?php

declare(strict_types=1);

use App\Modules\Campaigns\Http\Controllers\CampaignAssignmentController;
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
| view (D-10). Inviting creators + assignment mutations land in Chunk 2.
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
    });
