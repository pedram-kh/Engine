<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * The notification subsystem module (S11.0). Owns the custom `notifications`
 * table, the NotificationType vocabulary, the per-user preferences, the emit
 * seam (NotificationService), and the per-user `/me/notifications` surface.
 *
 * Messaging (S11) and the Ch2 retrofit / fan-out consume this module via
 * NotificationService — they do not reach into its internals.
 */
final class NotificationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // NotificationService is a plain, dependency-free service — the
        // container autowires it. No explicit binding needed.
    }

    public function boot(): void
    {
        $this->registerRoutes();
    }

    private function registerRoutes(): void
    {
        if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
            return;
        }

        Route::middleware('api')
            ->prefix('api/v1')
            ->group(__DIR__.'/Routes/api.php');
    }
}
