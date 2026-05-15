<?php

declare(strict_types=1);

namespace App\TestHelpers;

use App\TestHelpers\Http\Middleware\ApplyTestClock;
use App\TestHelpers\Http\Middleware\ApplyTestQueueModeMiddleware;
use App\TestHelpers\Http\Middleware\VerifyTestHelperToken;
use App\TestHelpers\Services\RateLimiterNeutralizer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->registerQueueModeMiddleware();
        $this->applyNeutralizedRateLimiters();
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

    /**
     * Re-register any cache-flagged named limiters with `Limit::none()`.
     *
     * `bootstrap/providers.php` lists this provider AFTER
     * `IdentityServiceProvider`, so by the time this method runs the
     * production `auth-ip` / `auth-login-email` / `auth-password` /
     * `auth-resend-verification` registrations are in place. Calling
     * `RateLimiter::for($name, …)` again overwrites the registration —
     * that's the same primitive `LoginTest::beforeEach` uses for
     * the chunk-5 in-isolation pattern.
     *
     * Static call to `app()` instead of injection: providers cannot
     * type-hint container-built dependencies in `boot()` at the same
     * lifecycle point; `app()` resolves the singleton from the
     * already-bootstrapped container without an extra binding.
     */
    private function applyNeutralizedRateLimiters(): void
    {
        /** @var RateLimiterNeutralizer $neutralizer */
        $neutralizer = $this->app->make(RateLimiterNeutralizer::class);

        foreach ($neutralizer->list() as $name) {
            RateLimiter::for($name, static fn (Request $request): Limit => Limit::none());
        }
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

    /**
     * Push the queue-mode override middleware onto the `api` group.
     *
     * Sprint 3 Chunk 3 sub-step 4. Scoped to `api` rather than
     * prepended globally because queue-config overrides are only
     * meaningful for API requests that dispatch jobs — the `web`
     * stack (mock-vendor pages, auth screens) doesn't depend on
     * the queue driver.
     */
    private function registerQueueModeMiddleware(): void
    {
        $kernel = $this->app->make(Kernel::class);

        if ($kernel instanceof HttpKernel) {
            $kernel->appendMiddlewareToGroup('api', ApplyTestQueueModeMiddleware::class);
        }
    }
}
