<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use App\Modules\Identity\Http\Requests\ForgotPasswordRequest;
use App\Modules\Identity\Http\Requests\ResetPasswordRequest;
use App\Modules\Identity\Services\PasswordResetResult;
use App\Modules\Identity\Services\PasswordResetService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/v1/auth/forgot-password
 *   - Always returns 204 (whether the email exists or not). User-enumeration
 *     defence per docs/05-SECURITY-COMPLIANCE.md §6.6 + OWASP guidance.
 *
 * POST /api/v1/auth/reset-password
 *   - 204 on success. The Form Request enforces the password rules:
 *     min length, max length, confirmation, HIBP-not-breached.
 *   - 400 with `auth.password.invalid_token` if the token is unknown,
 *     expired, or mismatched against the email.
 */
final class PasswordResetController
{
    public function forgot(ForgotPasswordRequest $request, PasswordResetService $service): Response
    {
        $service->request($request->emailInput(), $request);

        return new Response(status: 204);
    }

    public function reset(ResetPasswordRequest $request, PasswordResetService $service): Response
    {
        $result = $service->complete(
            email: $request->emailInput(),
            token: $request->tokenInput(),
            newPassword: $request->passwordInput(),
            request: $request,
        );

        return match ($result) {
            PasswordResetResult::Completed => new Response(status: 204),
            PasswordResetResult::InvalidToken => $this->invalidTokenResponse($request),
        };
    }

    private function invalidTokenResponse(ResetPasswordRequest $request): JsonResponse
    {
        return ErrorResponse::single(
            request: $request,
            status: 400,
            code: 'auth.password.invalid_token',
            title: trans('auth.reset.invalid_token'),
            source: ['pointer' => '/data/attributes/token'],
        );
    }
}
