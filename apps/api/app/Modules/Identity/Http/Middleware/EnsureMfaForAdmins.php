<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use App\Core\Errors\ErrorResponse;
use App\Modules\Identity\Models\User;
use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces mandatory 2FA on the admin guard.
 *
 * Chunk 5 priority #7: this middleware MUST run AFTER `auth:web_admin`
 * (so `$request->user()` resolves to the admin) and BEFORE the
 * controller (so an unenrolled admin cannot reach any business logic
 * other than the enrollment endpoints themselves).
 *
 * The route group registers the middleware order explicitly:
 *
 *   ['auth:web_admin', EnsureMfaForAdmins::class]
 *
 * Reachability rules:
 *   - Non-admin users that somehow hit a route guarded by this
 *     middleware are passed through (the `auth:web_admin` guard would
 *     have rejected them upstream anyway; we don't want to fail open
 *     by guessing).
 *   - Admins WITH 2FA enabled are passed through.
 *   - Admins WITHOUT 2FA enabled get a 403 envelope with
 *     `auth.mfa.enrollment_required` so the SPA redirects them to
 *     /auth/2fa/enable.
 *
 * Local-dev override (priority #11): when `auth.admin_mfa_enforced`
 * is `false` AND the application is running in the `local`
 * environment, the middleware is a no-op. The default value of the
 * flag is `true`; opting out requires an explicit env var.
 */
final class EnsureMfaForAdmins
{
    public const ENROLLMENT_REQUIRED_CODE = 'auth.mfa.enrollment_required';

    public function __construct(private readonly Repository $config) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->shouldEnforce($request)) {
            return $next($request);
        }

        /** @var User|null $user */
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        if ($user->hasTwoFactorEnabled()) {
            return $next($request);
        }

        return ErrorResponse::single(
            request: $request,
            status: 403,
            code: self::ENROLLMENT_REQUIRED_CODE,
            title: trans('auth.mfa.enrollment_required'),
            meta: ['enrollment_required' => true],
        );
    }

    private function shouldEnforce(Request $request): bool
    {
        $enforced = (bool) $this->config->get('auth.admin_mfa_enforced', true);

        if ($enforced) {
            return true;
        }

        // Opting out is only permitted in the local environment so a
        // misconfigured staging/prod env never silently disables MFA.
        return $this->config->get('app.env') !== 'local';
    }
}
