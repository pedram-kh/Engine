<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Http\Requests\LoginRequest;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Http\Responders\LoginFailureResponder;
use App\Modules\Identity\Services\AuthService;
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
            mfaCode: $request->mfaCodeInput(),
            cancelDeletion: $request->cancelDeletionInput(),
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

            default => LoginFailureResponder::respond(
                $request,
                $result,
                self::correctSpaUrlForUser($request),
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
