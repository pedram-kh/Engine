<?php

declare(strict_types=1);

namespace App\Modules\Creators;

use App\Modules\Creators\Integrations\Contracts\EsignProvider;
use App\Modules\Creators\Integrations\Contracts\KycProvider;
use App\Modules\Creators\Integrations\Contracts\PaymentProvider;
use App\Modules\Creators\Integrations\Stubs\DeferredEsignProvider;
use App\Modules\Creators\Integrations\Stubs\DeferredKycProvider;
use App\Modules\Creators\Integrations\Stubs\DeferredPaymentProvider;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class CreatorsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Sprint 3 Chunk 1 binds Deferred*Provider stubs that throw on
        // call. Sprint 3 Chunk 2 swaps them for Mock{Kind}Provider
        // implementations (and a future env-driven driver swap layers
        // in real adapters per docs/06-INTEGRATIONS.md § 13.1).
        //
        // The bindings live here (not in AppServiceProvider) so the
        // Creators module owns its integration seams — Identity
        // and Audit modules never import these contracts.
        $this->app->bind(KycProvider::class, DeferredKycProvider::class);
        $this->app->bind(EsignProvider::class, DeferredEsignProvider::class);
        $this->app->bind(PaymentProvider::class, DeferredPaymentProvider::class);
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
