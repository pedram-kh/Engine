<?php

declare(strict_types=1);

use App\Modules\Strategy\Http\Controllers\AngleController;
use App\Modules\Strategy\Http\Controllers\CompletionController;
use App\Modules\Strategy\Http\Controllers\ConceptController;
use App\Modules\Strategy\Http\Controllers\ConceptImageController;
use App\Modules\Strategy\Http\Controllers\ConceptLinkController;
use App\Modules\Strategy\Http\Controllers\MatrixController;
use App\Modules\Strategy\Http\Controllers\PersonaController;
use App\Modules\Strategy\Http\Controllers\StrategyController;
use App\Modules\Strategy\Http\Controllers\UspController;
use App\Modules\Strategy\Notes\NoteController;
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

        // PL-2.5 — "Mark as complete" and "Reopen" (USPs, Personas, Matrix; Concepts is automatic) and internal notes.
        Route::put('completions/{step}', [CompletionController::class, 'mark'])->whereIn('step', ['usps', 'personas', 'matrix'])->withTrashed()->name('waves.strategy.completions.mark');
        Route::delete('completions/{step}', [CompletionController::class, 'reopen'])->whereIn('step', ['usps', 'personas', 'matrix'])->withTrashed()->name('waves.strategy.completions.reopen');
        Route::get('notes', [NoteController::class, 'index'])->withTrashed()->name('waves.strategy.notes.index');
        Route::post('notes', [NoteController::class, 'store'])->withTrashed()->name('waves.strategy.notes.store');
        Route::patch('notes/{note}', [NoteController::class, 'update'])->withTrashed()->name('waves.strategy.notes.update');
        Route::delete('notes/{note}', [NoteController::class, 'destroy'])->withTrashed()->name('waves.strategy.notes.destroy');

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
        Route::post('personas/{persona}/angles/{angle}/use-matrix-line', [AngleController::class, 'useMatrixLine'])->withTrashed()->name('waves.strategy.angles.use-matrix-line');
        Route::delete('personas/{persona}/angles/{angle}', [AngleController::class, 'destroy'])->withTrashed()->name('waves.strategy.angles.destroy');

        // PL-2.2: the messaging matrix. One market per GET; the PUT takes 1 to 48 cell changes in one transaction.
        Route::get('matrix', [MatrixController::class, 'show'])->withTrashed()->name('waves.strategy.matrix.show');
        Route::put('matrix/cells', [MatrixController::class, 'save'])->withTrashed()->name('waves.strategy.matrix.save');

        // PL-2.3: concepts. `{concept}`, `{image}` and `{link}` are plain ulids looked up THROUGH the wave in the services.
        Route::get('concepts', [ConceptController::class, 'index'])->withTrashed()->name('waves.strategy.concepts.index');
        Route::post('concepts', [ConceptController::class, 'store'])->withTrashed()->name('waves.strategy.concepts.store');
        Route::patch('concepts/{concept}', [ConceptController::class, 'update'])->withTrashed()->name('waves.strategy.concepts.update');
        Route::delete('concepts/{concept}', [ConceptController::class, 'destroy'])->withTrashed()->name('waves.strategy.concepts.destroy');
        Route::post('concepts/{concept}/duplicate', [ConceptController::class, 'duplicate'])->withTrashed()->name('waves.strategy.concepts.duplicate');
        Route::post('concepts/{concept}/decision', [ConceptController::class, 'decide'])->withTrashed()->name('waves.strategy.concepts.decide');
        Route::get('concepts/{concept}/decisions', [ConceptController::class, 'decisionHistory'])->withTrashed()->name('waves.strategy.concepts.decisions');

        Route::post('concepts/{concept}/images/uploads/init', [ConceptImageController::class, 'init'])->withTrashed()->name('waves.strategy.concepts.images.init');
        Route::post('concepts/{concept}/images/uploads/complete', [ConceptImageController::class, 'complete'])->withTrashed()->name('waves.strategy.concepts.images.complete');
        Route::get('concepts/{concept}/images/{image}/file', [ConceptImageController::class, 'file'])->withTrashed()->name('waves.strategy.concepts.images.file');
        Route::get('concepts/{concept}/images/{image}/thumbnail', [ConceptImageController::class, 'thumbnail'])->withTrashed()->name('waves.strategy.concepts.images.thumbnail');
        Route::delete('concepts/{concept}/images/{image}', [ConceptImageController::class, 'destroy'])->withTrashed()->name('waves.strategy.concepts.images.destroy');

        Route::post('concepts/{concept}/links', [ConceptLinkController::class, 'store'])->withTrashed()->name('waves.strategy.concepts.links.store');
        Route::patch('concepts/{concept}/links/{link}', [ConceptLinkController::class, 'update'])->withTrashed()->name('waves.strategy.concepts.links.update');
        Route::delete('concepts/{concept}/links/{link}', [ConceptLinkController::class, 'destroy'])->withTrashed()->name('waves.strategy.concepts.links.destroy');
    });
