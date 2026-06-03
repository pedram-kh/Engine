<?php

declare(strict_types=1);

namespace App\Modules\TalentPools;

use App\Modules\TalentPools\Models\TalentPool;
use App\Modules\TalentPools\Policies\TalentPoolPolicy;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class TalentPoolsServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->registerPolicies();
        $this->registerRoutes();
    }

    private function registerPolicies(): void
    {
        Gate::policy(TalentPool::class, TalentPoolPolicy::class);
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
