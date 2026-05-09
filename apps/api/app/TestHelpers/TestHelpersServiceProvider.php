<?php

declare(strict_types=1);

namespace App\TestHelpers;

use App\TestHelpers\Http\Middleware\ApplyTestClock;
use App\TestHelpers\Http\Middleware\VerifyTestHelperToken;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the App\TestHelpers module if and only if the application
 * is running in `local` or `testing` AND the `TEST_HELPERS_TOKEN`
 * configuration is non-empty.
 *
 * This is the production-safety perimeter. A misconfigured staging or
 * production environment that somehow ends up with TEST_HELPERS_TOKEN
 * set in the env still cannot expose the helper surface, because the
 * `app()->environment(['local', 'testing'])` check fails first. A
 * developer who flips `APP_ENV=local` on staging would trip the
 * environment check via deployment health probes long before the
 * helpers became reachable.
 *
 * Inside an enabled environment, the provider:
 *   1. Loads the route file at `app/TestHelpers/Routes/api.php`. Each
 *      route is itself gated by `VerifyTestHelperToken` middleware so
 *      a runtime config flip closes the surface without a redeploy.
 *   2. Pushes the `ApplyTestClock` middleware onto the global stack
 *      so the Redis-backed test clock can replay across requests.
 *
 * Outside an enabled environment, the provider is a no-op. Production
 * pays only the autoload cost of this single class.
 */
final class TestHelpersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../../config/test_helpers.php',
            'test_helpers',
        );
    }

    public function boot(): void
    {
        if (! self::gateOpen()) {
            return;
        }

        $this->registerRoutes();
        $this->registerGlobalClockMiddleware();
    }

    /**
     * Single source of truth for "is the test-helpers surface enabled?".
     *
     * Read by:
     *   - The provider itself, to decide whether to register routes
     *     and middleware at boot time.
     *   - The {@see VerifyTestHelperToken} middleware on every request,
     *     so a runtime config flip closes the gate without a redeploy.
     *   - The {@see ApplyTestClock} middleware on every request, for the
     *     same reason.
     */
    public static function gateOpen(): bool
    {
        $env = (string) config('app.env', 'production');

        if (! in_array($env, ['local', 'testing'], true)) {
            return false;
        }

        return (string) config('test_helpers.token', '') !== '';
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

    private function registerGlobalClockMiddleware(): void
    {
        // Push onto the global stack rather than the `api` group: the
        // clock should apply to ANY request hitting the application
        // while a spec is active, including non-API entry points used
        // by future SDK shims. The middleware is itself a no-op when
        // no test clock is set, so the cost is one cache lookup per
        // request — only paid in local/testing.
        $kernel = $this->app->make(Kernel::class);

        if ($kernel instanceof HttpKernel) {
            $kernel->prependMiddleware(ApplyTestClock::class);
        }
    }
}
