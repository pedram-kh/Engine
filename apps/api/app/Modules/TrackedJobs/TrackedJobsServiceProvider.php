<?php

declare(strict_types=1);

namespace App\Modules\TrackedJobs;

use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class TrackedJobsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // No bindings yet — the module exposes a single read endpoint
        // and a model. Sprint 14+ may add a job-spawning service.
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
