<?php

declare(strict_types=1);

namespace App\Modules\Erasure;

use App\Modules\Erasure\Console\ErasureReapplyCommand;
use App\Modules\Erasure\Console\ErasureRunCommand;
use App\Modules\Erasure\Execution\AuditScrub;
use App\Modules\Erasure\Execution\DatabaseAuditScrub;
use App\Modules\Erasure\Features\AccountErasureExecuteEnabled;
use App\Modules\Erasure\Files\MediaDiskObjectStore;
use App\Modules\Erasure\Files\ObjectStore;
use App\Modules\Erasure\Ledger\DiskLedgerMirror;
use App\Modules\Erasure\Ledger\LedgerMirror;
use Illuminate\Support\ServiceProvider;
use Laravel\Pennant\Feature;

/**
 * AH-104a — the erasure engine module.
 *
 * It deliberately reaches across Identity, Creators, Campaigns, Messaging,
 * Notifications, Audit and TrackedJobs (see docs/reviews/account-deletion-erasure-plan.md
 * §4.1), so it owns that reach instead of making any one of them the owner of
 * the others' tables. It registers no route and no schedule: the way in is
 * `php artisan` and, from AH-104c C3, the admin Erase button (route in the Admin module, flag off by default).
 */
final class ErasureServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Plain services are autowired. The object store is the one door to storage (Stage 4f).
        $this->app->bind(ObjectStore::class, MediaDiskObjectStore::class);
        // The ledger copy outside the database (Stage 7).
        $this->app->bind(LedgerMirror::class, DiskLedgerMirror::class);
        // Stage 4e: the audit scrub, on the application connection (ruling 2026-10-21: no separate role).
        $this->app->bind(AuditScrub::class, DatabaseAuditScrub::class);
    }

    public function boot(): void
    {
        // AH-104c C3: the Erase button's flag, default off (docs/feature-flags.md).
        Feature::define(AccountErasureExecuteEnabled::NAME, AccountErasureExecuteEnabled::default());

        // No routes, no schedule, no listeners (by design — see the class doc). The only ways in
        // are the artisan command and the admin Erase button (its route lives in the Admin module).
        if ($this->app->runningInConsole()) {
            $this->commands([ErasureRunCommand::class, ErasureReapplyCommand::class]);
        }
    }
}
