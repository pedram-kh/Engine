<?php

declare(strict_types=1);

use App\Modules\Boards\Http\Controllers\BoardAutomationController;
use App\Modules\Boards\Http\Controllers\BoardCardController;
use App\Modules\Boards\Http\Controllers\BoardColumnController;
use App\Modules\Boards\Http\Controllers\BoardController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Boards module routes (Sprint 12 Chunk 1, D-10)
|--------------------------------------------------------------------------
|
| All routes are tenant-scoped to an agency under the standard stack:
|   - auth:web       — requires authenticated session
|   - tenancy.agency — resolves {agency} binding, verifies membership, sets context
|   - tenancy        — fail-closed guard: 500 if context missing
|
| See docs/security/tenancy.md §3 (these are STANDARD agency-scoped routes, NOT
| allowlist bypasses). Gates inside the controllers:
|   - GET board / GET movements / GET automations → `view` (any member)
|   - column CRUD + reorder + automation update    → `update` (admin + manager)
|   - manual card move                             → `invite` (admin + manager + staff)
|
| Backend only — the SPA Kanban is Chunk 2. reset-to-defaults is Chunk 2 (Q3).
|
*/

Route::middleware(['auth:web', 'tenancy.agency', 'tenancy'])
    ->prefix('agencies/{agency}')
    ->group(function (): void {
        // The full board (lazy-heals on first fetch, D-4).
        Route::get('campaigns/{campaign}/board', [BoardController::class, 'show'])
            ->name('campaigns.board.show');

        // Reset-to-defaults — the destructive re-seed (Sprint 12 Chunk 3, D-7;
        // update-gated, the column-CRUD precedent).
        Route::post('campaigns/{campaign}/board/reset-to-defaults', [BoardController::class, 'reset'])
            ->name('campaigns.board.reset');

        // Column CRUD + reorder (§7).
        Route::post('campaigns/{campaign}/board/columns', [BoardColumnController::class, 'store'])
            ->name('campaigns.board.columns.store');
        Route::patch('campaigns/{campaign}/board/columns/reorder', [BoardColumnController::class, 'reorder'])
            ->name('campaigns.board.columns.reorder');
        Route::patch('campaigns/{campaign}/board/columns/{column}', [BoardColumnController::class, 'update'])
            ->name('campaigns.board.columns.update');
        Route::delete('campaigns/{campaign}/board/columns/{column}', [BoardColumnController::class, 'destroy'])
            ->name('campaigns.board.columns.destroy');

        // Automation config (§8).
        Route::get('campaigns/{campaign}/board/automations', [BoardAutomationController::class, 'index'])
            ->name('campaigns.board.automations.index');
        Route::patch('campaigns/{campaign}/board/automations/{automation}', [BoardAutomationController::class, 'update'])
            ->name('campaigns.board.automations.update');

        // Manual move + movement history (§5.4 + §13).
        Route::post('campaigns/{campaign}/board/cards/{card}/move', [BoardCardController::class, 'move'])
            ->name('campaigns.board.cards.move');
        Route::get('campaigns/{campaign}/board/cards/{card}/movements', [BoardCardController::class, 'movements'])
            ->name('campaigns.board.cards.movements');
    });
