<?php

declare(strict_types=1);

use App\Modules\Brands\Http\Controllers\BrandController;
use App\Modules\Brands\Http\Controllers\BrandLogoController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Brands module routes
|--------------------------------------------------------------------------
|
| All routes are tenant-scoped to an agency. The middleware stack:
|   - auth:web       — requires authenticated session
|   - tenancy.agency — resolves {agency} binding, verifies membership, sets context
|   - tenancy        — fail-closed guard: 500 if context missing
|
| See docs/security/tenancy.md §3 for the full contract.
|
*/

Route::middleware(['auth:web', 'tenancy.agency', 'tenancy'])
    ->prefix('agencies/{agency}')
    ->group(function (): void {
        // CRUD — index, store, show, update, destroy (archive) are all
        // handled by BrandController via authorizeResource().
        Route::apiResource('brands', BrandController::class);

        // Sprint 3 Chunk 4 sub-step 6 — Brand Restore UI.
        // Reverses an archive (soft-delete + status flip). Surfaces the
        // existing BrandPolicy::restore gate + BrandRestored audit action
        // to the frontend's archive-filter restore action.
        Route::post('brands/{brand}/restore', [BrandController::class, 'restore'])
            ->name('brands.restore');

        // AH-053 (D7) — brand logo, direct multipart (the avatar pattern).
        // Tenant-scoped like everything else in this group; the controller
        // adds the explicit brand-belongs-to-agency check that route-model
        // binding cannot do, and gates on the brand UPDATE policy.
        // Recorded in docs/security/tenancy.md §3.
        Route::post('brands/{brand}/logo', [BrandLogoController::class, 'store'])
            ->name('brands.logo.store');
        Route::delete('brands/{brand}/logo', [BrandLogoController::class, 'destroy'])
            ->name('brands.logo.destroy');
    });
