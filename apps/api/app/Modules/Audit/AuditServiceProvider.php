<?php

declare(strict_types=1);

namespace App\Modules\Audit;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Audit\Http\Middleware\RequireActionReason;
use App\Modules\Audit\Services\AuditLogger;
use Illuminate\Foundation\AliasLoader;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

final class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Single shared instance so DI consumers and the Audit facade
        // produce equivalent rows. See tests/Feature/Modules/Audit/AuditLoggerTest.php.
        $this->app->singleton(AuditLogger::class);

        AliasLoader::getInstance()->alias('Audit', Audit::class);
    }

    public function boot(): void
    {
        $this->registerMiddleware();
    }

    private function registerMiddleware(): void
    {
        /** @var Router $router */
        $router = $this->app->make('router');

        // Routes that mutate sensitive state declare ->middleware('action.reason').
        // Contract: docs/04-API-DESIGN.md §26.
        $router->aliasMiddleware('action.reason', RequireActionReason::class);
    }
}
