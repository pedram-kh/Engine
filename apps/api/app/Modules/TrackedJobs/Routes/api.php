<?php

declare(strict_types=1);

use App\Modules\TrackedJobs\Http\Controllers\GetJobController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| TrackedJobs module routes
|--------------------------------------------------------------------------
|
| Mounted by TrackedJobsServiceProvider under the 'api' middleware group
| with prefix '/api/v1'.
|
| The polling endpoint is intentionally NOT under any tenancy.* middleware
| because TrackedJobs are addressable cross-tenant by their ulid (per
| spec § 18). The controller enforces ownership/tenant-membership inline.
|
| Cross-tenant route allowlist (docs/security/tenancy.md § 4):
|   GET /api/v1/jobs/{job}      Sprint 3 Chunk 1
|
*/

Route::middleware(['auth:web', 'tenancy.set'])->group(function (): void {
    Route::get('jobs/{job}', GetJobController::class)->name('jobs.show');
});
