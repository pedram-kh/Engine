<?php

declare(strict_types=1);

namespace App\Modules\Strategy;

use App\Modules\Strategy\Features\StrategyEnabled;
use App\Modules\Strategy\Models\WavePersona;
use App\Modules\Strategy\Models\WavePersonaAngle;
use App\Modules\Strategy\Models\WaveUsp;
use App\Modules\Strategy\Policies\WaveStrategyPolicy;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Pennant\Feature;

final class StrategyServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Feature::define(StrategyEnabled::NAME, StrategyEnabled::default());
        Gate::policy(WaveUsp::class, WaveStrategyPolicy::class);
        Gate::policy(WavePersona::class, WaveStrategyPolicy::class);
        Gate::policy(WavePersonaAngle::class, WaveStrategyPolicy::class);
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
