<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use App\Modules\Identity\Http\Requests\ConfirmTwoFactorRequest;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\TwoFactorConfirmationStatus;
use App\Modules\Identity\Services\TwoFactorEnrollmentService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/v1/auth/2fa/confirm
 *
 * Step 2 of the two-step enrollment flow. Verifies the user's first
 * TOTP code against the cached provisional secret and, on success,
 * persists the secret + recovery codes + confirmation timestamp in
 * a single DB transaction.
 *
 * The plaintext recovery codes are returned in the response body and
 * are the ONLY chance the user has to save them. The audit row that
 * fires for `mfa.confirmed` does NOT contain them (chunk 5 priority #6).
 */
final class ConfirmTwoFactorController
{
    public function __invoke(ConfirmTwoFactorRequest $request, TwoFactorEnrollmentService $enrollment): Response
    {
        /** @var User $user */
        $user = $request->user();

        $result = $enrollment->confirm(
            user: $user,
            provisionalToken: $request->provisionalToken(),
            code: $request->code(),
            request: $request,
        );

        return match ($result->status) {
            TwoFactorConfirmationStatus::Confirmed => new JsonResponse([
                'data' => [
                    'recovery_codes' => $result->recoveryCodes,
                ],
            ], 200),

            TwoFactorConfirmationStatus::AlreadyConfirmed => ErrorResponse::single(
                request: $request,
                status: 409,
                code: 'auth.mfa.already_enabled',
                title: trans('auth.mfa.already_enabled'),
            ),

            TwoFactorConfirmationStatus::ProvisionalNotFound => ErrorResponse::single(
                request: $request,
                status: 410,
                code: 'auth.mfa.provisional_expired',
                title: trans('auth.mfa.provisional_expired'),
            ),

            TwoFactorConfirmationStatus::InvalidCode => ErrorResponse::single(
                request: $request,
                status: 400,
                code: 'auth.mfa.invalid_code',
                title: trans('auth.mfa.invalid_code'),
            ),
        };
    }
}
