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
 * The middleware is request-aware: it only flips the cookie name when
 * the path begins with `api/v1/admin/`. All other requests go through
 * untouched and continue to use `catalyst_main_session`.
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
        return str_starts_with(ltrim($request->path(), '/'), self::PATH_PREFIX);
    }
}
