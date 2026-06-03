<?php

declare(strict_types=1);

use App\Modules\TalentPools\Http\Controllers\CreatorTalentPoolController;
use App\Modules\TalentPools\Http\Controllers\TalentPoolController;
use App\Modules\TalentPools\Http\Controllers\TalentPoolMembershipController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Talent Pools module routes (Sprint 6 Chunk 2b)
|--------------------------------------------------------------------------
|
| All routes are tenant-scoped to an agency. The middleware stack mirrors
| the brands block:
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
        // CRUD — index / store / show / update / destroy (archive). Mirrors
        // BrandController via apiResource. The resource parameter is pinned to
        // `talentPool` (camel) so every route below shares one param name.
        Route::apiResource('talent-pools', TalentPoolController::class)
            ->parameters(['talent-pools' => 'talentPool']);

        // Restore an archived (soft-deleted) pool. Mirrors brands.restore.
        Route::post('talent-pools/{talentPool}/restore', [TalentPoolController::class, 'restore'])
            ->name('talent-pools.restore');

        // ─── Membership (the net-new pivot-write surface, D-2b-8) ────────────
        // GET    members (paginated, detail page)
        // POST   add a creator (idempotent)
        // DELETE remove a creator
        Route::get('talent-pools/{talentPool}/creators', [TalentPoolMembershipController::class, 'index'])
            ->name('talent-pools.creators.index');
        Route::post('talent-pools/{talentPool}/creators', [TalentPoolMembershipController::class, 'store'])
            ->name('talent-pools.creators.store');
        Route::delete('talent-pools/{talentPool}/creators/{creator}', [TalentPoolMembershipController::class, 'destroy'])
            ->name('talent-pools.creators.destroy');

        // ─── Add-to-pool picker fetch (D-2b-9) ───────────────────────────────
        // The agency's pools + an is_member flag for this creator (one query,
        // no N+1). Consumed by the AddToPoolDialog on the 2a detail page.
        Route::get('creators/{creator}/talent-pools', [CreatorTalentPoolController::class, 'index'])
            ->name('agencies.creators.talent-pools.index');
    });
