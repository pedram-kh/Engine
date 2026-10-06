<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Http\Requests\TokenLoginRequest;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Http\Responders\LoginFailureResponder;
use App\Modules\Identity\Services\AuthService;
use App\Modules\Identity\Services\LoginResultStatus;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/v1/auth/token — the creator app's login (AH-099, D1).
 *
 * A separate door onto the SAME gate graph as POST /auth/login
 * ({@see AuthService::issueToken()} vs `login()`), kept as its own controller
 * so the web login controller stays untouched. Failure envelopes come from
 * the shared {@see LoginFailureResponder}; only the success shape differs —
 * the user resource plus `meta.token` (a bearer token, shown once).
 *
 * Creator-only: an agency member or platform admin with valid credentials
 * gets the same `403 auth.wrong_spa` the web door gives a wrong-side login,
 * with `meta.correct_spa_url` pointing at the SPA their account type belongs
 * to (no new i18n keys). No token is minted for them.
 */
final class TokenLoginController
{
    public function __invoke(TokenLoginRequest $request, AuthService $auth): Response
    {
        $result = $auth->issueToken(
            email: $request->emailInput(),
            password: $request->passwordInput(),
            request: $request,
            platform: $request->platformInput(),
            mfaCode: $request->mfaCodeInput(),
        );

        if ($result->status === LoginResultStatus::Success && $result->user !== null && $result->token !== null) {
            $minutes = config('sanctum.expiration');
            $accessToken = $result->token->accessToken;
            $expiresAt = is_numeric($minutes) && (int) $minutes > 0
                ? $accessToken->created_at?->copy()->addMinutes((int) $minutes)
                : $accessToken->expires_at;

            return UserResource::make($result->user)
                ->additional(['meta' => [
                    'token' => $result->token->plainTextToken,
                    'token_type' => 'Bearer',
                    'expires_at' => $expiresAt?->toIso8601String(),
                ]])
                ->response();
        }

        return LoginFailureResponder::respond(
            $request,
            $result,
            self::correctSpaUrlForUser($result->user?->type),
        );
    }

    private static function correctSpaUrlForUser(?UserType $type): string
    {
        return $type === UserType::PlatformAdmin
            ? (string) config('app.frontend_admin_url', '')
            : (string) config('app.frontend_main_url', '');
    }
}
