<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\TwoFactorEnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/v1/auth/2fa/enable
 *
 * Step 1 of the two-step enrollment flow (chunk 5 priority #8).
 * Returns the provisional token, otpauth URL, an inline SVG QR code,
 * and the manual entry secret. The user's row is NOT mutated; the
 * provisional state lives in cache for 10 minutes. Calling /enable
 * again issues a fresh provisional token and invalidates any
 * previously-issued one (the cache key is per-token, so the old token
 * is simply unreachable from the user's perspective).
 *
 * If the user already has 2FA confirmed, returns 409 with
 * `auth.mfa.already_enabled`. The user must /disable first.
 */
final class EnableTwoFactorController
{
    public function __invoke(Request $request, TwoFactorEnrollmentService $enrollment): Response
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasTwoFactorEnabled()) {
            return ErrorResponse::single(
                request: $request,
                status: 409,
                code: 'auth.mfa.already_enabled',
                title: trans('auth.mfa.already_enabled'),
            );
        }

        $result = $enrollment->start($user, $request);

        return new JsonResponse([
            'data' => [
                'provisional_token' => $result->provisionalToken,
                'otpauth_url' => $result->otpauthUrl,
                'qr_code_svg' => $result->qrCodeSvg,
                'manual_entry_key' => $result->manualEntryKey,
                'expires_in_seconds' => $result->expiresInSeconds,
            ],
        ], 200);
    }
}
