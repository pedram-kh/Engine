<?php

declare(strict_types=1);

use App\Modules\Audit\Concerns\Audited;
use App\Modules\Audit\Models\IntegrationCredential;
use App\Modules\Audit\Models\IntegrationEvent;
use App\Modules\Creators\Http\Controllers\MockVendorController;
use App\Modules\Creators\Http\Controllers\Webhooks\EsignWebhookController;
use App\Modules\Creators\Http\Controllers\Webhooks\KycWebhookController;
use App\Modules\Creators\Http\Controllers\WizardCompletionController;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| Sprint 3 Chunk 2 sub-step 10 — cross-cutting invariants
|--------------------------------------------------------------------------
|
| Source-inspection regressions (#1) for the structural choices the
| chunk-2 plan locked in. Each test is the kind of invariant whose
| silent regression would re-open a closed security or process gap;
| the failure surfaces the change to the reviewer.
|
| Pins:
|
|   1. integration_credentials.credentials uses the `encrypted:array`
|      cast (docs/03-DATA-MODEL.md § 23). Widening this to plain
|      `array` re-opens a P0 secret-leak surface.
|
|   2. IntegrationEvent does NOT use the Audited trait or extend any
|      Audited base. Refinement 5 from the user — vendor-payload
|      history is its own log; auditing IntegrationEvent rows would
|      double-log the integration.webhook.received / .processed
|      audits we already emit explicitly from InboundWebhookIngestor.
|
|   3. The `webhooks` named rate limiter is registered as
|      1000 req/min per provider segment (docs/04-API-DESIGN.md § 13),
|      keyed on the trailing path segment so a noisy KYC vendor
|      cannot starve the eSign quota.
|
|   4. Webhook routes are tenant-less + unauthenticated — no
|      `auth:*`, no `tenancy*` middleware on the registered route.
|      Rationale lives in docs/security/tenancy.md § 4.
|
|   5. Mock-vendor routes are unauthenticated (the unguessable
|      session-token in the path is the only authenticator) — no
|      `auth:*` middleware on the route.
|
|   6. Wizard-completion endpoints SIT INSIDE the creators.me.*
|      group (auth:web + tenancy.set + verified) — they read
|      authenticated state.
|
*/

it('integration_credentials.credentials cast is encrypted:array (docs/03-DATA-MODEL.md § 23)', function (): void {
    $reflection = new ReflectionClass(IntegrationCredential::class);
    $casts = $reflection->getMethod('casts');
    $casts->setAccessible(true);

    $resolved = $casts->invoke(new IntegrationCredential);

    expect($resolved['credentials'])->toBe(
        'encrypted:array',
        'IntegrationCredential::casts()[credentials] MUST stay encrypted:array — widening to plain `array` is a P0 secret-leak regression.',
    );
});

it('IntegrationEvent does NOT use the Audited trait or auto-emit audit rows (Refinement 5)', function (): void {
    $traits = class_uses_recursive(IntegrationEvent::class);

    expect($traits)->not->toHaveKey(
        Audited::class,
        'IntegrationEvent must NOT use the Audited trait — vendor-payload history is logged separately by InboundWebhookIngestor (integration.webhook.received / .processed audit actions). Auto-auditing here would double-log every webhook.',
    );
});

it('webhooks rate limiter resolves to 1000 req/min keyed on the trailing path segment', function (): void {
    $resolver = RateLimiter::limiter('webhooks');
    expect($resolver)->not->toBeNull('expected the `webhooks` named limiter to be registered by CreatorsServiceProvider::registerRateLimiters()');

    /** @var Closure $resolver */
    $kycRequest = Request::create('/api/v1/webhooks/kyc');
    $esignRequest = Request::create('/api/v1/webhooks/esign');

    $kycLimit = $resolver($kycRequest);
    $esignLimit = $resolver($esignRequest);

    expect($kycLimit)->toBeInstanceOf(Limit::class);
    expect($esignLimit)->toBeInstanceOf(Limit::class);

    expect($kycLimit->maxAttempts)->toBe(1000);
    expect($esignLimit->maxAttempts)->toBe(1000);

    // The keys are intentionally distinct so a noisy KYC vendor
    // cannot starve eSign — verifies the per-provider segmentation.
    expect($kycLimit->key)->not->toBe($esignLimit->key);
});

it('webhook routes are unauthenticated + tenant-less (allowlist invariant)', function (): void {
    $registered = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with(ltrim($route->uri(), '/'), 'api/v1/webhooks/'))
        ->values();

    expect($registered)->not->toBeEmpty('expected at least the kyc + esign webhook routes to be registered');

    foreach ($registered as $route) {
        $middleware = $route->gatherMiddleware();
        foreach ($middleware as $name) {
            expect($name)->not->toStartWith(
                'auth:',
                "webhook route {$route->uri()} MUST NOT carry auth middleware (vendor traffic is unauthenticated; signature verification is the auth substitute).",
            );
            expect($name)->not->toBe(
                'tenancy.set',
                "webhook route {$route->uri()} MUST NOT carry tenancy middleware (allowlisted as tenant-less in docs/security/tenancy.md § 4).",
            );
            expect($name)->not->toBe(
                'tenancy',
                "webhook route {$route->uri()} MUST NOT carry tenancy fail-closed middleware.",
            );
        }
    }
});

it('mock-vendor routes are unauthenticated (session-token-in-URL is the authenticator)', function (): void {
    $registered = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with(ltrim($route->uri(), '/'), '_mock-vendor/'))
        ->values();

    expect($registered)->not->toBeEmpty('expected the 6 mock-vendor routes (3 kinds × show + complete) to be registered');

    foreach ($registered as $route) {
        $middleware = $route->gatherMiddleware();
        foreach ($middleware as $name) {
            expect($name)->not->toStartWith(
                'auth:',
                "mock-vendor route {$route->uri()} MUST NOT carry auth middleware — anonymous-session UX by design.",
            );
        }
    }
});

it('webhook + mock-vendor controllers are wired to the registered routes', function (): void {
    // Pin the action map so a path/controller rename doesn't silently
    // bypass the middleware checks above.
    $expected = [
        'api/v1/webhooks/kyc' => KycWebhookController::class,
        'api/v1/webhooks/esign' => EsignWebhookController::class,
    ];

    foreach ($expected as $uri => $controller) {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($r): bool => ltrim($r->uri(), '/') === $uri);

        if ($route === null) {
            throw new RuntimeException("expected {$uri} to be registered");
        }

        expect($route->getActionName())->toContain($controller);
    }

    $mockVendor = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r): bool => str_starts_with(ltrim($r->uri(), '/'), '_mock-vendor/'))
        ->values();

    foreach ($mockVendor as $route) {
        expect($route->getActionName())->toContain(MockVendorController::class);
    }
});

it('wizard-completion endpoints DO carry auth + verified middleware (creator-scoped)', function (): void {
    $expected = [
        'api/v1/creators/me/wizard/kyc/status',
        'api/v1/creators/me/wizard/kyc/return',
        'api/v1/creators/me/wizard/contract/status',
        'api/v1/creators/me/wizard/contract/return',
        'api/v1/creators/me/wizard/payout/status',
        'api/v1/creators/me/wizard/payout/return',
    ];

    foreach ($expected as $uri) {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($r): bool => ltrim($r->uri(), '/') === $uri && in_array('GET', $r->methods(), true));

        if ($route === null) {
            throw new RuntimeException("expected {$uri} to be registered");
        }

        expect($route->getActionName())->toContain(WizardCompletionController::class);

        $middleware = $route->gatherMiddleware();
        expect($middleware)->toContain('auth:web')
            ->and($middleware)->toContain('tenancy.set')
            ->and($middleware)->toContain('verified');
    }
});
