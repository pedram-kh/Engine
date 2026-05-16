<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use App\Modules\Identity\Http\Requests\LoginRequest;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Services\AuthService;
use App\Modules\Identity\Services\FailedLoginTracker;
use App\Modules\Identity\Services\LoginResult;
use App\Modules\Identity\Services\LoginResultStatus;
use App\Modules\Identity\Services\TwoFactorVerificationThrottle;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/v1/auth/login            (web guard, main SPA)
 * POST /api/v1/admin/auth/login      (web_admin guard, admin SPA)
 *
 * Thin wrapper around {@see AuthService::login()}; all business logic and
 * security side-effects live in the service. Maps each
 * {@see LoginResultStatus} branch onto the documented error envelopes
 * from docs/04-API-DESIGN.md §4 and §8.
 */
final class LoginController
{
    public function __invoke(LoginRequest $request, AuthService $auth): Response
    {
        $guard = self::resolveGuard($request);

        $result = $auth->login(
            email: $request->emailInput(),
            password: $request->passwordInput(),
            request: $request,
            guard: $guard,
            mfaCode: $request->mfaCodeInput(),
        );

        return self::respond($request, $result);
    }

    private static function resolveGuard(LoginRequest $request): string
    {
        $route = $request->route();

        if ($route !== null) {
            $name = $route->getName();
            if (is_string($name) && str_starts_with($name, 'admin.')) {
                return 'web_admin';
            }
        }

        return 'web';
    }

    private static function respond(LoginRequest $request, LoginResult $result): Response
    {
        return match ($result->status) {
            LoginResultStatus::Success => UserResource::make($result->user)->response(),

            LoginResultStatus::WrongSpa => ErrorResponse::single(
                request: $request,
                status: 403,
                code: 'auth.wrong_spa',
                title: trans('auth.login.wrong_spa'),
                // The receiving SPA uses `meta.correct_spa_url` to render
                // a "Go to the right login page" link. We always return
                // the OTHER SPA's URL (relative to the guard that just
                // rejected the user) so the wrong-side flow has a single,
                // unambiguous next step.
                meta: ['correct_spa_url' => self::correctSpaUrlForUser($request)],
            ),

            LoginResultStatus::InvalidCredentials => ErrorResponse::single(
                request: $request,
                status: 401,
                code: 'auth.invalid_credentials',
                title: trans('auth.login.invalid_credentials'),
            ),

            LoginResultStatus::MfaRequired => ErrorResponse::single(
                request: $request,
                status: 401,
                code: 'auth.mfa_required',
                title: trans('auth.login.mfa_required'),
                meta: ['mfa_required' => true],
            ),

            LoginResultStatus::MfaInvalidCode => ErrorResponse::single(
                request: $request,
                status: 401,
                code: 'auth.mfa.invalid_code',
                title: trans('auth.mfa.invalid_code'),
                meta: ['mfa_required' => true],
            ),

            LoginResultStatus::MfaRateLimited => ErrorResponse::single(
                request: $request,
                status: 423,
                code: 'auth.mfa.rate_limited',
                title: trans('auth.mfa.rate_limited', [
                    'minutes' => TwoFactorVerificationThrottle::WINDOW_MINUTES,
                ]),
                headers: ['Retry-After' => (string) ($result->retryAfterSeconds ?? 0)],
            ),

            LoginResultStatus::MfaEnrollmentSuspended => ErrorResponse::single(
                request: $request,
                status: 423,
                code: 'auth.mfa.enrollment_suspended',
                title: trans('auth.mfa.enrollment_suspended'),
            ),

            LoginResultStatus::AccountSuspended => ErrorResponse::single(
                request: $request,
                status: 423,
                code: 'auth.account_locked.suspended',
                title: trans('auth.login.account_locked'),
            ),

            LoginResultStatus::TemporarilyLocked => ErrorResponse::single(
                request: $request,
                status: 423,
                code: 'auth.account_locked.temporary',
                title: trans('auth.login.account_locked_temporary', [
                    'minutes' => FailedLoginTracker::SHORT_WINDOW_MINUTES,
                ]),
                headers: ['Retry-After' => (string) ($result->retryAfterSeconds ?? 0)],
            ),
        };
    }

    /**
     * Resolves the "other-side" SPA URL for the guard that just
     * rejected the user. The wrong-SPA envelope carries this on
     * `meta.correct_spa_url` so the SPA can render a one-click hop.
     *
     * Routing-by-name lets us mirror {@see resolveGuard()} exactly —
     * any future admin login route still flows through this branch.
     */
    private static function correctSpaUrlForUser(LoginRequest $request): string
    {
        return self::resolveGuard($request) === 'web_admin'
            ? (string) config('app.frontend_main_url', '')
            : (string) config('app.frontend_admin_url', '');
    }
}
