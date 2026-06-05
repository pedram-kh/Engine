<?php

declare(strict_types=1);

namespace App\Modules\Campaigns;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Policies\CampaignPolicy;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class CampaignsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Module contract bindings are added by the sprint that builds this module.
    }

    public function boot(): void
    {
        $this->registerPolicies();
        $this->registerRoutes();
    }

    private function registerPolicies(): void
    {
        Gate::policy(Campaign::class, CampaignPolicy::class);
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
