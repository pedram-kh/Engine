<?php

declare(strict_types=1);

namespace App\Modules\Agencies;

use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Agencies\Policies\AgencyCreatorRelationPolicy;
use App\Modules\Agencies\Services\AgencyInvitationService;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class AgenciesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AgencyInvitationService::class);
    }

    public function boot(): void
    {
        $this->registerPolicies();
        $this->registerRoutes();
    }

    private function registerPolicies(): void
    {
        Gate::policy(AgencyCreatorRelation::class, AgencyCreatorRelationPolicy::class);
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
