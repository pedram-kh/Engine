<?php

declare(strict_types=1);

use App\Modules\Strategy\Http\Controllers\AngleController;
use App\Modules\Strategy\Http\Controllers\MatrixController;
use App\Modules\Strategy\Http\Controllers\PersonaController;
use App\Modules\Strategy\Http\Controllers\StrategyController;
use App\Modules\Strategy\Http\Controllers\UspController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Strategy module routes (PL-2.1, PL-2.2)
|--------------------------------------------------------------------------
|
| Standard tenant stack (auth:web + tenancy.agency + tenancy), nested under the wave. Every action starts with the
| flag check (BOTH `waves_enabled` and `strategy_enabled`: 404 `not_found`), then the brand and wave checks (404).
| `{usp}` is a plain ulid looked up THROUGH the wave in the service, so a card of another wave or agency is a 404.
| See docs/security/tenancy.md §3.
|
*/

Route::middleware(['auth:web', 'tenancy.agency', 'tenancy'])
    ->prefix('agencies/{agency}/brands/{brand}/waves/{wave}/strategy')
    ->group(function (): void {
        Route::get('/', [StrategyController::class, 'show'])->withTrashed()->name('waves.strategy.show');
        Route::patch('audience-title', [StrategyController::class, 'audienceTitle'])->withTrashed()->name('waves.strategy.audience-title');

        Route::get('usps', [UspController::class, 'index'])->withTrashed()->name('waves.strategy.usps.index');
        Route::post('usps', [UspController::class, 'store'])->withTrashed()->name('waves.strategy.usps.store');
        // Literal route BEFORE `{usp}`.
        Route::put('usps/order', [UspController::class, 'reorder'])->withTrashed()->name('waves.strategy.usps.order');
        Route::patch('usps/{usp}', [UspController::class, 'update'])->withTrashed()->name('waves.strategy.usps.update');
        Route::delete('usps/{usp}', [UspController::class, 'destroy'])->withTrashed()->name('waves.strategy.usps.destroy');

        Route::get('personas', [PersonaController::class, 'index'])->withTrashed()->name('waves.strategy.personas.index');
        Route::post('personas', [PersonaController::class, 'store'])->withTrashed()->name('waves.strategy.personas.store');
        // Literal route BEFORE `{persona}`.
        Route::post('personas/copy', [PersonaController::class, 'copy'])->withTrashed()->name('waves.strategy.personas.copy');
        Route::patch('personas/{persona}', [PersonaController::class, 'update'])->withTrashed()->name('waves.strategy.personas.update');
        Route::delete('personas/{persona}', [PersonaController::class, 'destroy'])->withTrashed()->name('waves.strategy.personas.destroy');

        Route::post('personas/{persona}/angles', [AngleController::class, 'store'])->withTrashed()->name('waves.strategy.angles.store');
        Route::patch('personas/{persona}/angles/{angle}', [AngleController::class, 'update'])->withTrashed()->name('waves.strategy.angles.update');
        Route::delete('personas/{persona}/angles/{angle}', [AngleController::class, 'destroy'])->withTrashed()->name('waves.strategy.angles.destroy');

        // PL-2.2: the messaging matrix. One market per GET; the PUT takes 1 to 48 cell changes in one transaction.
        Route::get('matrix', [MatrixController::class, 'show'])->withTrashed()->name('waves.strategy.matrix.show');
        Route::put('matrix/cells', [MatrixController::class, 'save'])->withTrashed()->name('waves.strategy.matrix.save');
    });
