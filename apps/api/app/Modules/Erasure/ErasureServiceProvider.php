<?php

declare(strict_types=1);

namespace App\Modules\Erasure;

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
        // Plain services; the container autowires them.
    }

    public function boot(): void
    {
        // No routes, no schedule, no listeners (by design — see the class doc).
    }
}
