<?php

declare(strict_types=1);

use App\Modules\Messaging\Http\Controllers\AgencyMessageController;
use App\Modules\Messaging\Http\Controllers\AgencyRelationshipMessageController;
use App\Modules\Messaging\Http\Controllers\CreatorMessageController;
use App\Modules\Messaging\Http\Controllers\CreatorRelationshipMessageController;
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
        Route::post('campaigns/{campaign}/assignments/{assignment}/messages/attachments/init', [AgencyMessageController::class, 'attachmentInit'])
            ->name('messages.attachments.init');
        Route::post('campaigns/{campaign}/assignments/{assignment}/messages/attachments/complete', [AgencyMessageController::class, 'attachmentComplete'])
            ->name('messages.attachments.complete');
    });

Route::prefix('creators/me/assignments/{assignment}')
    ->name('creators.me.assignments.messages.')
    ->middleware(['auth:web', 'tenancy.set', 'verified'])
    ->group(function (): void {
        Route::get('messages', [CreatorMessageController::class, 'index'])->name('index');
        Route::post('messages', [CreatorMessageController::class, 'store'])->name('store');
        Route::post('messages/read', [CreatorMessageController::class, 'markRead'])->name('read');
        Route::post('messages/attachments/init', [CreatorMessageController::class, 'attachmentInit'])->name('attachments.init');
        Route::post('messages/attachments/complete', [CreatorMessageController::class, 'attachmentComplete'])->name('attachments.complete');
    });

/*
|--------------------------------------------------------------------------
| Relationship messaging (AH-010) — 1:1 connected agency↔creator
|--------------------------------------------------------------------------
|
| A parallel surface to campaign messaging above, on the mirrored relationship
| spine. Send is gated by the status-aware `canMessageRelationship` ability
| (approved creator + roster + non-blacklisted, D2). Symmetric inbox both sides
| (Q5); the agency surface is keyed by {creator}, the creator surface by the
| {agency} ULID (a creator holds at most one thread per agency).
|
| Creator surface is allowlisted in docs/security/tenancy.md §4 (the
| creators/me BelongsToAgency bypass, the CreatorMessageController precedent).
|
*/

Route::middleware(['auth:web', 'tenancy.agency', 'tenancy'])
    ->prefix('agencies/{agency}')
    ->name('agencies.relationship-messaging.')
    ->group(function (): void {
        Route::get('relationship-threads', [AgencyRelationshipMessageController::class, 'inbox'])
            ->name('inbox');
        Route::get('creators/{creator}/relationship-messages', [AgencyRelationshipMessageController::class, 'index'])
            ->name('messages.index');
        Route::post('creators/{creator}/relationship-messages', [AgencyRelationshipMessageController::class, 'store'])
            ->name('messages.store');
        Route::post('creators/{creator}/relationship-messages/read', [AgencyRelationshipMessageController::class, 'markRead'])
            ->name('messages.read');
        Route::post('creators/{creator}/relationship-messages/attachments/init', [AgencyRelationshipMessageController::class, 'attachmentInit'])
            ->name('messages.attachments.init');
        Route::post('creators/{creator}/relationship-messages/attachments/complete', [AgencyRelationshipMessageController::class, 'attachmentComplete'])
            ->name('messages.attachments.complete');
    });

Route::prefix('creators/me/relationship-threads')
    ->name('creators.me.relationship-messaging.')
    ->middleware(['auth:web', 'tenancy.set', 'verified'])
    ->group(function (): void {
        Route::get('/', [CreatorRelationshipMessageController::class, 'inbox'])->name('inbox');
        Route::get('{agency}/messages', [CreatorRelationshipMessageController::class, 'index'])->name('messages.index');
        Route::post('{agency}/messages', [CreatorRelationshipMessageController::class, 'store'])->name('messages.store');
        Route::post('{agency}/messages/read', [CreatorRelationshipMessageController::class, 'markRead'])->name('messages.read');
        Route::post('{agency}/messages/attachments/init', [CreatorRelationshipMessageController::class, 'attachmentInit'])->name('messages.attachments.init');
        Route::post('{agency}/messages/attachments/complete', [CreatorRelationshipMessageController::class, 'attachmentComplete'])->name('messages.attachments.complete');
    });
