<?php

declare(strict_types=1);

namespace App\Modules\Waves;

use App\Modules\Waves\Features\WavesEnabled;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Pennant\Feature;

final class WavesServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Feature::define(WavesEnabled::NAME, WavesEnabled::default());
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
