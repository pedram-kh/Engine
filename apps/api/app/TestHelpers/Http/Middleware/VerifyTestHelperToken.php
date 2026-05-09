<?php

declare(strict_types=1);

namespace App\TestHelpers\Http\Middleware;

use App\TestHelpers\TestHelpersServiceProvider;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-request gate for every `/api/v1/_test/*` route.
 *
 * Returns a bare 404 when the gate is closed. We deliberately do NOT
 * use a 401 / 403 with the standard error envelope — a 404 is
 * indistinguishable from any other unknown route, so an attacker
 * probing the surface cannot tell whether the helper exists at all.
 *
 * The gate is closed when ANY of the following are true:
 *   - The application is not running in `local` or `testing`
 *     (TestHelpersServiceProvider already prevents registration in
 *     production, but we re-check here as belt-and-suspenders for
 *     route caches and exotic environments).
 *   - `config('test_helpers.token')` is empty or unset.
 *   - The `X-Test-Helper-Token` header is missing or does not match
 *     the configured token under hash_equals (constant-time).
 *
 * The middleware is registered as a route-level middleware on each
 * test-helper route — never globally — so production traffic and
 * regular API traffic do not pay any cost from it.
 */
final class VerifyTestHelperToken
{
    public const HEADER = 'X-Test-Helper-Token';

    public function handle(Request $request, Closure $next): Response
    {
        if (! TestHelpersServiceProvider::gateOpen()) {
            return $this->bareNotFound();
        }

        $expected = (string) config('test_helpers.token', '');
        $provided = (string) $request->header(self::HEADER, '');

        if ($expected === '' || ! hash_equals($expected, $provided)) {
            return $this->bareNotFound();
        }

        return $next($request);
    }

    private function bareNotFound(): Response
    {
        // Bare 404 with no body. Mirrors what Laravel's RouteCollection
        // would have returned had the route never existed — the entire
        // point of the gate.
        return new Response('', 404);
    }
}
