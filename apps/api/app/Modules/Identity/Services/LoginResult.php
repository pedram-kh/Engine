<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\User;
use Carbon\CarbonInterface;
use Laravel\Sanctum\NewAccessToken;

/**
 * Discriminated result of {@see AuthService::login()}.
 *
 * One of these states applies on every login attempt; controllers map
 * directly onto the documented error envelopes from
 * docs/04-API-DESIGN.md §8 + §4 without inspecting any other state.
 *
 *   - Success            → user authenticated, session attached. The
 *                          User instance is populated for downstream
 *                          response serialization.
 *   - InvalidCredentials → wrong email or wrong password.
 *                          `auth.invalid_credentials`. 401.
 *   - MfaRequired             → password OK and the user has 2FA confirmed,
 *                              but no `mfa_code` was supplied. 401 with
 *                              `auth.mfa_required`. The SPA prompts the
 *                              user, then re-submits with `mfa_code` set.
 *   - MfaInvalidCode          → `mfa_code` was supplied but neither the
 *                              TOTP verifier nor the recovery-code path
 *                              accepted it. 401 with `auth.mfa.invalid_code`.
 *                              The verification throttle has already
 *                              incremented the per-user counter.
 *   - MfaRateLimited          → too many invalid TOTP/recovery attempts in
 *                              the 15-minute sliding window. 423 with
 *                              `auth.mfa.rate_limited` + Retry-After.
 *   - MfaEnrollmentSuspended  → the hard threshold (10 invalid attempts
 *                              in 15 min) tripped — admin must clear
 *                              `users.two_factor_enrollment_suspended_at`.
 *                              423 with `auth.mfa.enrollment_suspended`.
 *   - AccountSuspended   → user is hard-suspended (is_suspended=true).
 *                          `auth.account_locked.suspended`. 423.
 *   - TemporarilyLocked  → too many failures in the 15-minute window.
 *                          `auth.account_locked.temporary`. 423.
 *   - DeletionPending    → credentials (and 2FA) are right but the creator asked
 *                          for deletion and the grace is running. 403
 *                          `auth.deletion_pending`; no session or token, no
 *                          failure counter. The same request with
 *                          `cancel_deletion` restores the account.
 *   - WrongSpa           → credentials are valid but the user's type is
 *                          not allowed for the SPA the request hit. 403
 *                          with `auth.wrong_spa`. The session is NOT
 *                          attached, no failed-login counter increment
 *                          (the credentials were correct), and the
 *                          response carries the correct SPA URL in
 *                          `meta.correct_spa_url` so the wrong-side SPA
 *                          can offer a one-click redirect.
 */
final readonly class LoginResult
{
    private function __construct(
        public LoginResultStatus $status,
        public ?User $user = null,
        public ?int $retryAfterSeconds = null,
        public ?NewAccessToken $token = null,
        public ?CarbonInterface $scheduledFor = null,
        public ?string $mfaVia = null,
    ) {}

    /**
     * `$token` is set only by the token door (AH-099); the session door
     * leaves it null, exactly as before.
     */
    public static function success(User $user, ?NewAccessToken $token = null): self
    {
        return new self(LoginResultStatus::Success, user: $user, token: $token);
    }

    public static function invalidCredentials(): self
    {
        return new self(LoginResultStatus::InvalidCredentials);
    }

    public static function mfaRequired(User $user): self
    {
        return new self(LoginResultStatus::MfaRequired, user: $user);
    }

    public static function mfaInvalidCode(User $user): self
    {
        return new self(LoginResultStatus::MfaInvalidCode, user: $user);
    }

    public static function mfaRateLimited(User $user, int $retryAfterSeconds): self
    {
        return new self(LoginResultStatus::MfaRateLimited, user: $user, retryAfterSeconds: $retryAfterSeconds);
    }

    public static function mfaEnrollmentSuspended(User $user): self
    {
        return new self(LoginResultStatus::MfaEnrollmentSuspended, user: $user);
    }

    public static function accountSuspended(User $user): self
    {
        return new self(LoginResultStatus::AccountSuspended, user: $user);
    }

    public static function temporarilyLocked(int $retryAfterSeconds): self
    {
        return new self(LoginResultStatus::TemporarilyLocked, retryAfterSeconds: $retryAfterSeconds);
    }

    /**
     * @param  'none'|'totp'|'recovery_code'  $mfaVia  how the second factor was satisfied on THIS request,
     *                                                 so the client knows whether a recovery code was spent
     */
    public static function deletionPending(User $user, string $mfaVia): self
    {
        return new self(LoginResultStatus::DeletionPending, user: $user, scheduledFor: $user->deletion_scheduled_for, mfaVia: $mfaVia);
    }

    public static function wrongSpa(User $user): self
    {
        return new self(LoginResultStatus::WrongSpa, user: $user);
    }
}
