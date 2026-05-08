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

            LoginResultStatus::AccountSuspended => ErrorResponse::single(
                request: $request,
                status: 423,
                code: 'auth.account_locked',
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
}
