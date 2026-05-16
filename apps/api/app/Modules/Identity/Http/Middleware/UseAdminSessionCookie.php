<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forces the admin SPA to use a distinct session cookie name from the main
 * SPA so the two cannot stomp on each other when both run on 127.0.0.1
 * during local development. Browsers do not isolate cookies by port, so the
 * two SPAs share an origin and would otherwise overwrite each other's
 * `catalyst_main_session` cookie on every request.
 *
 * Registered as a *global* middleware (prepended via bootstrap/app.php so
 * it executes before Sanctum's `EnsureFrontendRequestsAreStateful` —
 * which is itself the thing that injects StartSession dynamically). Acting
 * earlier is mandatory because StartSession reads `config('session.cookie')`
 * exactly once when it runs.
 *
 * The middleware applies on two distinct request shapes:
 *
 *   1. **Admin API surface** — any request whose path begins with
 *      `api/v1/admin/`. Path-based detection is sufficient because every
 *      admin-side controller lives under that prefix by convention
 *      (see docs/runbooks/local-dev.md).
 *
 *   2. **Sanctum CSRF preflight from the admin SPA's origin** — the
 *      `/sanctum/csrf-cookie` route is owned by Sanctum and does NOT carry
 *      our admin prefix. Without special handling, an admin-SPA preflight
 *      lands on the main session cookie, stores the CSRF token in the
 *      main session, and the next admin-API POST (which DOES flip to the
 *      admin session) sees a token mismatch and 419s. Detecting via the
 *      browser-sent `Origin` / `Referer` header is the simplest cure: when
 *      the request originates from the configured admin SPA URL
 *      (`config('app.frontend_admin_url')`), treat the preflight as
 *      admin-side too so the CSRF token is stored in the admin session
 *      from the start.
 *
 * Production / staging keep the same logical setup but the host axis
 * (app.* vs admin.*) does the isolation independently — the middleware
 * remains harmless under any scheme.
 *
 * See docs/runbooks/local-dev.md for the full cookie-isolation contract.
 */
final class UseAdminSessionCookie
{
    public const COOKIE = 'catalyst_admin_session';

    public const PATH_PREFIX = 'api/v1/admin';

    public const CSRF_PREFLIGHT_PATH = 'sanctum/csrf-cookie';

    public function __construct(private readonly Repository $config) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (self::shouldApply($request)) {
            $this->config->set('session.cookie', self::COOKIE);
        }

        return $next($request);
    }

    public static function shouldApply(Request $request): bool
    {
        $path = ltrim($request->path(), '/');

        if (str_starts_with($path, self::PATH_PREFIX)) {
            return true;
        }

        if ($path === self::CSRF_PREFLIGHT_PATH && self::originIsAdminSpa($request)) {
            return true;
        }

        return false;
    }

    /**
     * Browser-sent `Origin` (preferred — populated on cross-origin fetches
     * and on same-origin POSTs in modern browsers) or `Referer` (fallback
     * for the request shapes where Origin is suppressed, e.g. some
     * navigation-initiated GETs). Matches against the canonical admin SPA
     * URL from `config('app.frontend_admin_url')`. Origin comparison
     * strips trailing slashes so both `http://127.0.0.1:5174` and
     * `http://127.0.0.1:5174/` count as the same host.
     *
     * If `app.frontend_admin_url` is empty (mis-configured environment),
     * the gate fails closed — no preflight gets the cookie switch, which
     * matches today's behaviour rather than silently widening trust.
     */
    private static function originIsAdminSpa(Request $request): bool
    {
        $expected = self::normalise((string) config('app.frontend_admin_url', ''));
        if ($expected === '') {
            return false;
        }

        $rawOrigin = (string) $request->headers->get('Origin', '');
        if ($rawOrigin !== '' && self::normalise($rawOrigin) === $expected) {
            return true;
        }

        $referer = (string) $request->headers->get('Referer', '');
        if ($referer === '') {
            return false;
        }

        $parts = parse_url($referer);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $reconstructed = $parts['scheme'].'://'.$parts['host'].$port;

        return self::normalise($reconstructed) === $expected;
    }

    private static function normalise(string $origin): string
    {
        return rtrim(strtolower($origin), '/');
    }
}
