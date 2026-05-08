<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use App\Modules\Identity\Http\Requests\VerifyEmailRequest;
use App\Modules\Identity\Services\EmailVerificationResult;
use App\Modules\Identity\Services\EmailVerificationService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/v1/auth/verify-email
 *
 *   - 204 No Content on first successful verification.
 *   - 409 Conflict with `auth.email.already_verified` on a re-click of an
 *     already-verified user. This makes the single-use guarantee
 *     observable to the SPA without leaking whether the underlying token
 *     was technically still inside its 24h window.
 *   - 400 Bad Request with `auth.email.verification_invalid` on bad
 *     signature, malformed payload, mismatched email_hash, or unknown
 *     user_id. Same code for all four cases — we don't tell the caller
 *     which check tripped.
 *   - 410 Gone with `auth.email.verification_expired` when the token
 *     decoded cleanly but the 24h window has elapsed (per
 *     docs/05-SECURITY-COMPLIANCE.md §6.5). The SPA prompts the user to
 *     resend the link.
 */
final class VerifyEmailController
{
    public function __invoke(VerifyEmailRequest $request, EmailVerificationService $service): Response
    {
        $result = $service->verify($request->tokenInput(), $request);

        return match ($result) {
            EmailVerificationResult::Verified => new Response(status: 204),
            EmailVerificationResult::AlreadyVerified => $this->alreadyVerified($request),
            EmailVerificationResult::InvalidToken => $this->invalidToken($request),
            EmailVerificationResult::ExpiredToken => $this->expiredToken($request),
        };
    }

    private function alreadyVerified(VerifyEmailRequest $request): JsonResponse
    {
        return ErrorResponse::single(
            request: $request,
            status: Response::HTTP_CONFLICT,
            code: 'auth.email.already_verified',
            title: trans('auth.email_verification.already_verified'),
        );
    }

    private function invalidToken(VerifyEmailRequest $request): JsonResponse
    {
        return ErrorResponse::single(
            request: $request,
            status: Response::HTTP_BAD_REQUEST,
            code: 'auth.email.verification_invalid',
            title: trans('auth.email_verification.verification_invalid'),
            source: ['pointer' => '/data/attributes/token'],
        );
    }

    private function expiredToken(VerifyEmailRequest $request): JsonResponse
    {
        return ErrorResponse::single(
            request: $request,
            status: Response::HTTP_GONE,
            code: 'auth.email.verification_expired',
            title: trans('auth.email_verification.verification_expired'),
            source: ['pointer' => '/data/attributes/token'],
        );
    }
}
