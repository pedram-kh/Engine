<?php

declare(strict_types=1);

namespace App\TestHelpers\Http\Controllers;

use App\TestHelpers\Services\RateLimiterNeutralizer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * POST   /api/v1/_test/rate-limiter/{name}   — neutralise a named limiter
 * DELETE /api/v1/_test/rate-limiter/{name}   — restore the production callback
 *
 * REQUIRES EXPLICIT afterEach RESTORE
 * ----------------------------------
 * The neutralised state lives in shared cache and survives across
 * Playwright tests (and across `php artisan serve`'s per-request PHP
 * processes — that's the whole point). Every spec that POSTs here MUST
 * call DELETE in `afterEach`, or the next spec runs against a silently-
 * neutralised limiter and the production-shape assertions of unrelated
 * specs become meaningless. The `neutralizeThrottle` /
 * `restoreThrottle` Playwright fixtures enforce the pair at the call
 * site by matching name; the convention is identical to the chunk-6.1
 * test-clock contract (`afterEach` resetClock).
 *
 * Production-safety perimeter
 * ---------------------------
 * Inherits the chunk-6.1 layered gating: env in {local,testing} AND
 * {@see App\TestHelpers\TestHelpersServiceProvider::gateOpen()} OR the
 * route never hits the controller at all (bare 404 from
 * {@see App\TestHelpers\Http\Middleware\VerifyTestHelperToken}). No
 * additional gates are wired here — the surface is uniformly behind
 * the same shared-secret token as the rest of `_test/*`.
 *
 * In-process re-registration
 * --------------------------
 * After updating the cache list, POST also calls `RateLimiter::for($name, …)`
 * inline so the CURRENT process honours the override on subsequent
 * requests in the same Pest test (without waiting for a fresh provider
 * boot). Across `php artisan serve` requests, a fresh PHP process
 * boots all providers and {@see App\TestHelpers\TestHelpersServiceProvider::boot()}
 * re-applies neutralisation by reading the cache list — so the
 * persistence path and the in-process path agree.
 *
 * DELETE deliberately does NOT re-register the production callback:
 * - In `php artisan serve`, the next request will boot
 *   IdentityServiceProvider afresh (production callback wins) and
 *   TestHelpersServiceProvider's apply-loop sees the empty cache list
 *   (no-op).
 * - In Pest, the test-helper's contract is "the cache list is
 *   updated"; tests that need same-process restoration are vanishingly
 *   rare and can call IdentityServiceProvider's boot path directly.
 */
final class NeutralizeRateLimiterController
{
    public function store(Request $request, string $name, RateLimiterNeutralizer $neutralizer): JsonResponse
    {
        if (! in_array($name, RateLimiterNeutralizer::ALLOWED_NAMES, true)) {
            return $this->unknownName($name);
        }

        $neutralizer->neutralize($name);

        // In-process re-registration so the override takes effect on
        // subsequent requests inside the SAME PHP process (Pest tests
        // that compose neutralise-then-call-login). Across processes,
        // {@see TestHelpersServiceProvider::boot()} re-applies from
        // the cache list — the same callback shape.
        RateLimiter::for($name, static fn (Request $req): Limit => Limit::none());

        return new JsonResponse([
            'data' => [
                'name' => $name,
                'neutralized' => $neutralizer->list(),
            ],
        ]);
    }

    public function destroy(Request $request, string $name, RateLimiterNeutralizer $neutralizer): JsonResponse
    {
        if (! in_array($name, RateLimiterNeutralizer::ALLOWED_NAMES, true)) {
            return $this->unknownName($name);
        }

        $neutralizer->restore($name);

        // We deliberately do NOT re-register the production callback
        // here — see class docblock for the cross-process / in-process
        // reasoning.

        return new JsonResponse([
            'data' => [
                'name' => $name,
                'neutralized' => $neutralizer->list(),
            ],
        ]);
    }

    private function unknownName(string $name): JsonResponse
    {
        return new JsonResponse([
            'error' => sprintf(
                'unknown limiter name "%s"; allowed names: %s',
                $name,
                implode(', ', RateLimiterNeutralizer::ALLOWED_NAMES),
            ),
        ], 422);
    }
}
