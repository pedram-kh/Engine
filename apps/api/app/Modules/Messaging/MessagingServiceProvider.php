<?php

declare(strict_types=1);

namespace App\Modules\Messaging;

use App\Modules\Messaging\Features\MessagesV2InboxEnabled;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Pennant\Feature;

final class MessagingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Module contract bindings are added by the sprint that builds this module.
    }

    public function boot(): void
    {
        Feature::define(MessagesV2InboxEnabled::NAME, MessagesV2InboxEnabled::default());
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
