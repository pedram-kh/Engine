<?php

declare(strict_types=1);

namespace App\Modules\Creators;

use App\Modules\Creators\Features\ContractSigningEnabled;
use App\Modules\Creators\Features\CreatorPayoutMethodEnabled;
use App\Modules\Creators\Features\KycVerificationEnabled;
use App\Modules\Creators\Integrations\Contracts\EsignProvider;
use App\Modules\Creators\Integrations\Contracts\KycProvider;
use App\Modules\Creators\Integrations\Contracts\PaymentProvider;
use App\Modules\Creators\Integrations\Mock\MockEsignProvider;
use App\Modules\Creators\Integrations\Mock\MockKycProvider;
use App\Modules\Creators\Integrations\Mock\MockPaymentProvider;
use App\Modules\Creators\Integrations\Stripe\StripePaymentProvider;
use App\Modules\Creators\Integrations\Stubs\DeferredEsignProvider;
use App\Modules\Creators\Integrations\Stubs\DeferredKycProvider;
use App\Modules\Creators\Integrations\Stubs\DeferredPaymentProvider;
use App\Modules\Creators\Integrations\Stubs\SkippedEsignProvider;
use App\Modules\Creators\Integrations\Stubs\SkippedKycProvider;
use App\Modules\Creators\Integrations\Stubs\SkippedPaymentProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Pennant\Feature;
use Stripe\StripeClient;

final class CreatorsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Sprint 3 Chunk 2 sub-step 8 — flag-conditional + driver-
        // aware binding swap. Resolution order per provider:
        //
        //   1. Pennant flag OFF  → Skipped*Provider (throws
        //      FeatureDisabledException on every call). Closes the
        //      no-silent-vendor-calls invariant from
        //      docs/feature-flags.md.
        //
        //   2. Flag ON + driver = 'mock' (Sprint 3 default) →
        //      Mock*Provider. Backed by Cache for session state.
        //
        //   3. Flag ON + driver matches a real-vendor case (Sprint 4
        //      Chunk 2 ships the payment `stripe` driver →
        //      StripePaymentProvider, D-c2-9) → real adapter binding.
        //      An unrecognised driver falls through to the Deferred
        //      stub so a misconfigured driver in production fails
        //      loudly via ProviderNotBoundException at call time, not
        //      silently.
        //
        // The bindings live here (not in AppServiceProvider) so the
        // Creators module owns its integration seams — Identity and
        // Audit modules never import these contracts (#34 module
        // boundary).
        $this->app->bind(
            KycProvider::class,
            $this->makeProviderResolver(
                flagName: KycVerificationEnabled::NAME,
                configKey: 'integrations.kyc.driver',
                mockClass: MockKycProvider::class,
                deferredClass: DeferredKycProvider::class,
                skippedClass: SkippedKycProvider::class,
            ),
        );

        $this->app->bind(
            EsignProvider::class,
            $this->makeProviderResolver(
                flagName: ContractSigningEnabled::NAME,
                configKey: 'integrations.esign.driver',
                mockClass: MockEsignProvider::class,
                deferredClass: DeferredEsignProvider::class,
                skippedClass: SkippedEsignProvider::class,
            ),
        );

        // Sprint 4 Chunk 2 — the real Stripe Connect adapter. Built
        // with a StripeClient (test-mode key in test/staging; secret
        // material from AWS Secrets Manager per config/integrations.php)
        // + the non-secret return/refresh URLs + webhook signing
        // secret. Tests override the StripeClient binding with a fake
        // so no live calls hit Stripe in CI.
        $this->app->bind(StripeClient::class, static function ($app): StripeClient {
            return new StripeClient(
                (string) $app['config']->get('integrations.payment.stripe.secret_key', ''),
            );
        });

        $this->app->bind(StripePaymentProvider::class, static function ($app): StripePaymentProvider {
            $config = $app['config'];

            return new StripePaymentProvider(
                client: $app->make(StripeClient::class),
                webhookSecret: (string) $config->get('integrations.payment.stripe.webhook_secret', ''),
                returnUrl: (string) $config->get('integrations.payment.stripe.return_url', ''),
                refreshUrl: (string) $config->get('integrations.payment.stripe.refresh_url', ''),
                webhookTolerance: (int) $config->get('integrations.payment.stripe.webhook_tolerance', 300),
            );
        });

        $this->app->bind(
            PaymentProvider::class,
            $this->makeProviderResolver(
                flagName: CreatorPayoutMethodEnabled::NAME,
                configKey: 'integrations.payment.driver',
                mockClass: MockPaymentProvider::class,
                deferredClass: DeferredPaymentProvider::class,
                skippedClass: SkippedPaymentProvider::class,
                realDrivers: ['stripe' => StripePaymentProvider::class],
            ),
        );
    }

    public function boot(): void
    {
        $this->configurePennantScope();
        $this->registerFeatureFlags();
        $this->registerRateLimiters();
        $this->registerRoutes();
    }

    /**
     * Pin Pennant's default scope to `null` so the Phase 1 convention
     * "Feature::active(<flag>) (no scope arg) — operators flip flags
     * globally" actually evaluates against the operator-flipped
     * (null-scope) record rather than against the authenticated
     * user's per-user record (Pennant's out-of-the-box default).
     *
     * Without this override, an operator running
     * `Feature::activate('kyc_verification_enabled')` from a
     * non-authenticated context (artisan tinker, scheduler, queue
     * worker) writes a null-scope row, while an authenticated user's
     * subsequent `Feature::active('kyc_verification_enabled')` check
     * reads against the user's scope — they'd never line up.
     *
     * Phase 2+ may need to revisit this for genuinely per-user /
     * per-tenant flags; the resolver can be re-overridden per call
     * via `Feature::for($scope)->active(<flag>)`.
     */
    private function configurePennantScope(): void
    {
        Feature::resolveScopeUsing(static fn (): mixed => null);
    }

    /**
     * Build the closure container resolves to when an integration
     * contract is requested out of the container. Lazy by design —
     * the flag check + config lookup happen on each resolution so
     * tests can flip flags / driver config and see the binding
     * change without re-registering the provider.
     *
     * @param  class-string  $mockClass
     * @param  class-string  $deferredClass
     * @param  class-string  $skippedClass
     * @param  array<string, class-string>  $realDrivers  driver-string → real-adapter class.
     *                                                    Generic so each provider registers its own real vendors
     *                                                    (payment: `['stripe' => StripePaymentProvider::class]`;
     *                                                    Kyc/Esign add theirs the same way when they land).
     */
    private function makeProviderResolver(
        string $flagName,
        string $configKey,
        string $mockClass,
        string $deferredClass,
        string $skippedClass,
        array $realDrivers = [],
    ): \Closure {
        return function ($app) use ($flagName, $configKey, $mockClass, $deferredClass, $skippedClass, $realDrivers): object {
            if (! Feature::active($flagName)) {
                return $app->make($skippedClass);
            }

            $driver = $app['config']->get($configKey, 'mock');

            if ($driver === 'mock') {
                return $app->make($mockClass);
            }

            if (is_string($driver) && isset($realDrivers[$driver])) {
                return $app->make($realDrivers[$driver]);
            }

            return $app->make($deferredClass);
        };
    }

    /**
     * Register the `webhooks` named rate limiter the inbound vendor
     * webhook endpoints use (POST /api/v1/webhooks/{kyc,esign,stripe}).
     *
     * 1000 req/min per provider per docs/04-API-DESIGN.md § 13.
     * Keyed on the provider segment of the URL so a noisy vendor
     * cannot starve another's quota. The `kyc` / `esign` / `stripe`
     * segments come from the route prefix + controller path;
     * `$request->path()` is `api/v1/webhooks/stripe` etc. so we slice
     * off the trailing segment (the new `stripe` route is covered
     * automatically — no limiter change needed).
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('webhooks', static function (Request $request): Limit {
            $providerSegment = basename($request->path());

            return Limit::perMinute(1000)->by('webhooks:'.$providerSegment);
        });
    }

    /**
     * Register the three Phase-1 vendor-gating flags that the wizard
     * depends on (docs/feature-flags.md). Phase 1 flags are
     * operator-controlled and scope-less — call sites use
     * `Feature::active(<Class>::NAME)` (no scope arg). Each flag
     * class exposes a static `default()` returning a Closure so the
     * resolver runs on every check (Pennant treats non-Closure
     * arguments to `define()` as the literal stored value — see
     * Drivers/Decorator.php:153).
     */
    private function registerFeatureFlags(): void
    {
        Feature::define(KycVerificationEnabled::NAME, KycVerificationEnabled::default());
        Feature::define(CreatorPayoutMethodEnabled::NAME, CreatorPayoutMethodEnabled::default());
        Feature::define(ContractSigningEnabled::NAME, ContractSigningEnabled::default());
    }

    private function registerRoutes(): void
    {
        if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
            return;
        }

        Route::middleware('api')
            ->prefix('api/v1')
            ->group(__DIR__.'/Routes/api.php');

        // Mock-vendor pages (Sprint 3 Chunk 2 sub-step 5) live at the
        // top level (not under `/api/v1/`) because they render HTML
        // and rely on the standard `web` middleware group's session
        // + CSRF stack. Tenant-less + unauthenticated by design;
        // session token in the URL is unguessable per #42.
        Route::middleware('web')
            ->group(__DIR__.'/Routes/mock-vendor.php');
    }
}
