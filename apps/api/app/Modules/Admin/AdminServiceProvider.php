<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Core\Errors\ErrorResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class AdminServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Module contract bindings are added by the sprint that builds this module.
    }

    public function boot(): void
    {
        $this->registerRateLimits();
        $this->registerRoutes();
    }

    /**
     * AH-104c C3. Keyed on the signed-in admin, never the IP, and 429 only: neither limiter signs
     * anyone out or touches a lockout counter. The plan is a handful of reads (its duration on
     * production data is unmeasured, P9), so a few a minute; the Erase click also feeds the
     * per-user second-factor throttle, so this is the outer fence, not the only one.
     */
    private function registerRateLimits(): void
    {
        $limited = static fn (string $code, string $title): \Closure => static fn (Request $request, array $headers): JsonResponse => ErrorResponse::single(
            $request,
            429,
            $code,
            $title,
            headers: array_map('strval', $headers),
        );

        $key = static fn (Request $request): string => (string) ($request->user()?->getAuthIdentifier() ?? $request->ip());

        RateLimiter::for('admin-erasure-plan', static fn (Request $request): Limit => Limit::perMinute(6)
            ->by('erasure-plan:'.$key($request))
            ->response($limited('admin.erasure.plan.rate_limited', 'Too many plan requests.')));

        RateLimiter::for('admin-erasure-erase', static fn (Request $request): Limit => Limit::perHour(10)
            ->by('erasure-erase:'.$key($request))
            ->response($limited('admin.erasure.execute.rate_limited', 'Too many erase attempts.')));
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
