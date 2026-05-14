<?php

declare(strict_types=1);

use App\Modules\Creators\Http\Controllers\MockVendorController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mock vendor pages — Sprint 3 Chunk 2 sub-step 5
|--------------------------------------------------------------------------
|
| Mounted at the top level (not under /api/v1/) by
| {@see \App\Modules\Creators\CreatorsServiceProvider::registerRoutes()}
| so the Blade pages render with the standard `web` middleware
| group's session + CSRF stack. These exist to drive Playwright's
| redirect-bounce E2E (Chunk 3 critical-path #1 + #2) and to let a
| developer manually exercise the wizard in dev without a real
| vendor.
|
| Allowlisted in docs/security/tenancy.md § 4 as tenant-less.
|
*/

Route::prefix('_mock-vendor')->name('mock-vendor.')->group(function (): void {
    Route::get('kyc/{session}', [MockVendorController::class, 'showKyc'])->name('kyc.show');
    Route::post('kyc/{session}/complete', [MockVendorController::class, 'completeKyc'])->name('kyc.complete');

    Route::get('esign/{session}', [MockVendorController::class, 'showEsign'])->name('esign.show');
    Route::post('esign/{session}/complete', [MockVendorController::class, 'completeEsign'])->name('esign.complete');

    Route::get('stripe/{session}', [MockVendorController::class, 'showStripe'])->name('stripe.show');
    Route::post('stripe/{session}/complete', [MockVendorController::class, 'completeStripe'])->name('stripe.complete');
});
