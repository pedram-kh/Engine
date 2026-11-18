<?php

declare(strict_types=1);

use App\Modules\Waves\Http\Controllers\CampaignWaveController;
use App\Modules\Waves\Http\Controllers\WaveController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Waves module routes (PL-1.1)
|--------------------------------------------------------------------------
|
| Standard tenant stack (auth:web + tenancy.agency + tenancy), nested under the brand. Every action starts with
| the `waves_enabled` flag check (404 `not_found`). `{brand}` resolves archived brands too (read-only page);
| `{wave}` is a plain ulid looked up in the controller so a soft-deleted wave is a 404.
| See docs/security/tenancy.md §3.
|
*/

Route::middleware(['auth:web', 'tenancy.agency', 'tenancy'])
    ->prefix('agencies/{agency}')
    ->group(function (): void {
        Route::get('brands/{brand}/waves', [WaveController::class, 'index'])->withTrashed()->name('waves.index');
        Route::post('brands/{brand}/waves', [WaveController::class, 'store'])->withTrashed()->name('waves.store');
        // Literal route BEFORE `{wave}`.
        Route::get('brands/{brand}/waves/campaign-options', [WaveController::class, 'campaignOptions'])->withTrashed()->name('waves.campaign-options');
        Route::get('brands/{brand}/waves/{wave}', [WaveController::class, 'show'])->withTrashed()->name('waves.show');
        Route::patch('brands/{brand}/waves/{wave}', [WaveController::class, 'update'])->withTrashed()->name('waves.update');

        // PL-1.2 — the campaign side of the link. Same flag gate, same service.
        Route::get('campaigns/{campaign}/wave-options', [CampaignWaveController::class, 'options'])->name('campaigns.wave-options');
        Route::put('campaigns/{campaign}/wave', [CampaignWaveController::class, 'link'])->name('campaigns.wave.link');
        Route::delete('campaigns/{campaign}/wave', [CampaignWaveController::class, 'unlink'])->name('campaigns.wave.unlink');
    });
