<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use App\Modules\Identity\Features\AccountDeletionEnabled;
use App\Modules\Identity\Http\Requests\RequestAccountDeletionRequest;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AccountDeletionResult;
use App\Modules\Identity\Services\AccountDeletionService;
use App\Modules\Identity\Services\AccountDeletionStatus;
use App\Modules\Identity\Services\TwoFactorVerificationThrottle;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/v1/me/account-deletion (AH-104b-del A3)
 *
 * A creator schedules their own account for deletion. Creators only; the flag
 * `account_deletion_enabled` off answers 404, as if the route did not exist.
 *
 * Re-authentication failures are 403, never 401: the SPA treats every 401 as
 * "your session ended" and signs the person out, and a mistyped password on
 * this screen must not do that. (403 carries the same error codes sign-in
 * already uses, so the apps already know how to word them.)
 *
 * On success the caller is signed out here and now: every token and session
 * was revoked in the service, and a cookie session is invalidated so the
 * response does not re-create it.
 */
final class AccountDeletionController
{
    public function __invoke(RequestAccountDeletionRequest $request, AccountDeletionService $service): JsonResponse
    {
        if (! Feature::active(AccountDeletionEnabled::NAME)) {
            return ErrorResponse::single($request, Response::HTTP_NOT_FOUND, 'not_found', 'Not found.');
        }

        /** @var User $user */
        $user = $request->user();

        $result = $service->request($user, $request->password(), $request->mfaCode(), $request);

        if (! $result->succeeded()) {
            return $this->failure($request, $result);
        }

        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json([
            'data' => [
                'type' => 'account_deletion',
                'attributes' => [
                    'requested_at' => $result->requestedAt?->toIso8601String(),
                    'scheduled_for' => $result->scheduledFor?->toIso8601String(),
                ],
            ],
        ]);
    }

    private function failure(RequestAccountDeletionRequest $request, AccountDeletionResult $result): JsonResponse
    {
        return match ($result->status) {
            AccountDeletionStatus::InvalidPassword => ErrorResponse::single(
                request: $request,
                status: Response::HTTP_FORBIDDEN,
                code: 'auth.invalid_credentials',
                title: trans('auth.login.invalid_credentials'),
            ),
            AccountDeletionStatus::MfaRequired => ErrorResponse::single(
                request: $request,
                status: Response::HTTP_FORBIDDEN,
                code: 'auth.mfa_required',
                title: trans('auth.login.mfa_required'),
                meta: ['mfa_required' => true],
            ),
            AccountDeletionStatus::MfaInvalidCode => ErrorResponse::single(
                request: $request,
                status: Response::HTTP_FORBIDDEN,
                code: 'auth.mfa.invalid_code',
                title: trans('auth.mfa.invalid_code'),
                meta: ['mfa_required' => true],
            ),
            AccountDeletionStatus::MfaRateLimited => ErrorResponse::single(
                request: $request,
                status: Response::HTTP_LOCKED,
                code: 'auth.mfa.rate_limited',
                title: trans('auth.mfa.rate_limited', ['minutes' => TwoFactorVerificationThrottle::WINDOW_MINUTES]),
                headers: ['Retry-After' => (string) ($result->retryAfterSeconds ?? 0)],
            ),
            AccountDeletionStatus::MfaEnrollmentSuspended => ErrorResponse::single(
                request: $request,
                status: Response::HTTP_LOCKED,
                code: 'auth.mfa.enrollment_suspended',
                title: trans('auth.mfa.enrollment_suspended'),
            ),
            AccountDeletionStatus::Blocked => ErrorResponse::single(
                request: $request,
                status: Response::HTTP_UNPROCESSABLE_ENTITY,
                code: 'account_deletion.blocked',
                title: 'This account cannot be deleted yet.',
                meta: ['reasons' => $result->blockers],
            ),
            default => ErrorResponse::single(
                request: $request,
                status: Response::HTTP_FORBIDDEN,
                code: 'account_deletion.not_available',
                title: 'Account deletion is not available for this account.',
            ),
        };
    }
}
