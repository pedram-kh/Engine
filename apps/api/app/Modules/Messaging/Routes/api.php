<?php

declare(strict_types=1);

use App\Modules\Messaging\Http\Controllers\AgencyMessageController;
use App\Modules\Messaging\Http\Controllers\CreatorMessageController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Messaging module routes (Sprint 11)
|--------------------------------------------------------------------------
|
| Two surfaces onto the per-assignment thread (D-11/D-16):
|
| 1. AGENCY — tenant-scoped under the standard tenancy.agency + tenancy stack
|    (docs/security/tenancy.md §3). Route-model binding resolves the {agency},
|    {campaign}, {assignment} within the agency scope; the controller asserts
|    the campaign↔agency / assignment↔campaign edges. Reads gate on `view`,
|    sends on `message`.
|
| 2. CREATOR — under the creators/me/* stack (auth:web + tenancy.set + verified),
|    the BelongsToAgency scope deliberately bypassed (a creator may hold
|    assignments from many agencies). Ownership is STRUCTURAL: the assignment is
|    resolved within creator_id, so a non-owned ULID is 404. Allowlisted in
|    docs/security/tenancy.md §4.
|
*/

Route::middleware(['auth:web', 'tenancy.agency', 'tenancy'])
    ->prefix('agencies/{agency}')
    ->name('agencies.messaging.')
    ->group(function (): void {
        Route::get('campaigns/{campaign}/message-threads', [AgencyMessageController::class, 'rollup'])
            ->name('threads.rollup');
        Route::get('campaigns/{campaign}/assignments/{assignment}/messages', [AgencyMessageController::class, 'index'])
            ->name('messages.index');
        Route::post('campaigns/{campaign}/assignments/{assignment}/messages', [AgencyMessageController::class, 'store'])
            ->name('messages.store');
        Route::post('campaigns/{campaign}/assignments/{assignment}/messages/read', [AgencyMessageController::class, 'markRead'])
            ->name('messages.read');
    });

Route::prefix('creators/me/assignments/{assignment}')
    ->name('creators.me.assignments.messages.')
    ->middleware(['auth:web', 'tenancy.set', 'verified'])
    ->group(function (): void {
        Route::get('messages', [CreatorMessageController::class, 'index'])->name('index');
        Route::post('messages', [CreatorMessageController::class, 'store'])->name('store');
        Route::post('messages/read', [CreatorMessageController::class, 'markRead'])->name('read');
    });
