<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Responders;

use App\Core\Errors\ErrorResponse;
use App\Modules\Identity\Http\Controllers\LoginController;
use App\Modules\Identity\Services\FailedLoginTracker;
use App\Modules\Identity\Services\LoginResult;
use App\Modules\Identity\Services\LoginResultStatus;
use App\Modules\Identity\Services\TwoFactorVerificationThrottle;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The single mapping from a failed {@see LoginResult} onto the documented
 * error envelopes (docs/04-API-DESIGN.md §4 + §8), shared by the session
 * login ({@see LoginController}) and the
 * creator-app token login (AH-099). One mapping means the two doors can never
 * disagree about a status code or error code.
 *
 * Extracted from LoginController with the arms moved verbatim — the web
 * login's error envelopes are byte-identical post-extraction; the existing
 * login suites' assertions (LoginTest, WrongSpaGateTest, TwoFactorLoginTest,
 * AgencySuspensionLoginTest, AuthRateLimitTest) are that proof.
 *
 * `Success` is NOT handled here: what a success returns differs per door.
 */
final class LoginFailureResponder
{
    /**
     * @param  string  $correctSpaUrl  carried on `auth.wrong_spa` as `meta.correct_spa_url`
     */
    public static function respond(Request $request, LoginResult $result, string $correctSpaUrl): Response
    {
        return match ($result->status) {
            LoginResultStatus::Success => throw new \LogicException('LoginFailureResponder does not handle a successful login.'),

            LoginResultStatus::WrongSpa => ErrorResponse::single(
                request: $request,
                status: 403,
                code: 'auth.wrong_spa',
                title: trans('auth.login.wrong_spa'),
                // The receiving SPA uses `meta.correct_spa_url` to render
                // a "Go to the right login page" link. We always return
                // the OTHER SPA's URL (relative to the guard that just
                // rejected the user) so the wrong-side flow has a single,
                // unambiguous next step.
                meta: ['correct_spa_url' => $correctSpaUrl],
            ),

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

            LoginResultStatus::MfaInvalidCode => ErrorResponse::single(
                request: $request,
                status: 401,
                code: 'auth.mfa.invalid_code',
                title: trans('auth.mfa.invalid_code'),
                meta: ['mfa_required' => true],
            ),

            LoginResultStatus::MfaRateLimited => ErrorResponse::single(
                request: $request,
                status: 423,
                code: 'auth.mfa.rate_limited',
                title: trans('auth.mfa.rate_limited', [
                    'minutes' => TwoFactorVerificationThrottle::WINDOW_MINUTES,
                ]),
                headers: ['Retry-After' => (string) ($result->retryAfterSeconds ?? 0)],
            ),

            LoginResultStatus::MfaEnrollmentSuspended => ErrorResponse::single(
                request: $request,
                status: 423,
                code: 'auth.mfa.enrollment_suspended',
                title: trans('auth.mfa.enrollment_suspended'),
            ),

            LoginResultStatus::AccountSuspended => ErrorResponse::single(
                request: $request,
                status: 423,
                code: 'auth.account_locked.suspended',
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
