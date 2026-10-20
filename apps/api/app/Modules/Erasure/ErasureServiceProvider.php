<?php

declare(strict_types=1);

namespace App\Modules\Erasure;

use App\Modules\Erasure\Console\ErasureRunCommand;
use App\Modules\Erasure\Execution\AuditScrub;
use App\Modules\Erasure\Execution\UnavailableAuditScrub;
use App\Modules\Erasure\Files\MediaDiskObjectStore;
use App\Modules\Erasure\Files\ObjectStore;
use Illuminate\Support\ServiceProvider;

/**
 * AH-104a — the erasure engine module.
 *
 * It deliberately reaches across Identity, Creators, Campaigns, Messaging,
 * Notifications, Audit and TrackedJobs (see docs/reviews/account-deletion-erasure-plan.md
 * §4.1), so it owns that reach instead of making any one of them the owner of
 * the others' tables. It registers no route and no schedule: the only way in is
 * `php artisan`, and the command arrives in a later stage.
 */
final class ErasureServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Plain services are autowired. The object store is the one door to storage (Stage 4f).
        $this->app->bind(ObjectStore::class, MediaDiskObjectStore::class);
        // Stage 4e's slot. Unavailable until the scrub role exists (read-back C): execute refuses.
        $this->app->bind(AuditScrub::class, UnavailableAuditScrub::class);
    }

    public function boot(): void
    {
        // No routes, no schedule, no listeners (by design — see the class doc).
        // The one way in is the artisan command.
        if ($this->app->runningInConsole()) {
            $this->commands([ErasureRunCommand::class]);
        }
    }
}
