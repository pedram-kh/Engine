<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use App\Modules\Identity\Http\Requests\RegenerateRecoveryCodesRequest;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\TwoFactorChallengeService;
use App\Modules\Identity\Services\TwoFactorEnrollmentService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/v1/auth/2fa/recovery-codes
 *
 * Generates a fresh batch of recovery codes, replacing the previous
 * batch. Requires a working 2FA code so a stolen-session attacker
 * cannot rotate recovery codes silently. Plaintext codes are returned
 * once.
 *
 * Failure modes:
 *   - User does not have 2FA enabled → 409 `auth.mfa.not_enabled`.
 *   - mfa_code wrong → 401 `auth.mfa.invalid_code`.
 */
final class RegenerateRecoveryCodesController
{
    public function __invoke(
        RegenerateRecoveryCodesRequest $request,
        TwoFactorEnrollmentService $enrollment,
        TwoFactorChallengeService $challenge,
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

        $mfaResult = $challenge->verify($user, $request->mfaCode(), $request);

        if (! $mfaResult->passed) {
            return ErrorResponse::single(
                request: $request,
                status: 401,
                code: 'auth.mfa.invalid_code',
                title: trans('auth.mfa.invalid_code'),
            );
        }

        $codes = $enrollment->regenerateRecoveryCodes($user, $request);

        return new JsonResponse([
            'data' => [
                'recovery_codes' => $codes,
            ],
        ], 200);
    }
}
