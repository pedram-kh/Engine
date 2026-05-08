<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use App\Modules\Identity\Http\Requests\DisableTwoFactorRequest;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\TwoFactorChallengeService;
use App\Modules\Identity\Services\TwoFactorEnrollmentService;
use Illuminate\Contracts\Hashing\Hasher;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/v1/auth/2fa/disable
 *
 * Chunk 5 priority #10: disabling 2FA requires BOTH the user's current
 * password AND a working 2FA code (TOTP or recovery). This blocks the
 * "stolen session → silently disable 2FA → wait for password to leak"
 * attack chain.
 *
 * Failure modes return a single error code each:
 *   - User does not have 2FA enabled → 409 `auth.mfa.not_enabled`.
 *   - Password wrong OR mfa_code wrong → 401 `auth.mfa.invalid_code`.
 *     We don't disclose which factor failed (mirrors the
 *     login-failed-doesn't-fingerprint contract).
 *
 * On success: 204 No Content. The {@see TwoFactorEnrollmentService::disable()}
 * call wipes secret + recovery codes + confirmation timestamp in a
 * single transaction (priority #5).
 */
final class DisableTwoFactorController
{
    public function __invoke(
        DisableTwoFactorRequest $request,
        TwoFactorEnrollmentService $enrollment,
        TwoFactorChallengeService $challenge,
        Hasher $hasher,
    ): Response {
        /** @var User $user */
        $user = $request->user();

        if (! $user->hasTwoFactorEnabled()) {
            return ErrorResponse::single(
                request: $request,
                status: 409,
                code: 'auth.mfa.not_enabled',
                title: trans('auth.mfa.not_enabled'),
            );
        }

        $passwordOk = $hasher->check($request->password(), $user->password);
        $mfaResult = $challenge->verify($user, $request->mfaCode(), $request);

        if (! $passwordOk || ! $mfaResult->passed) {
            return ErrorResponse::single(
                request: $request,
                status: 401,
                code: 'auth.mfa.invalid_code',
                title: trans('auth.mfa.invalid_code'),
            );
        }

        $enrollment->disable($user, $request);

        return response()->noContent();
    }
}
