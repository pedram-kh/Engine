<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\User;
use Illuminate\Http\Request;
use SensitiveParameter;

/**
 * "Verified at the click": a TOTP code or a recovery code typed for ONE sensitive action by a
 * signed-in person (the creator's account-deletion request, the admin's Erase button).
 *
 * The rules are sign-in's, in one place:
 *   - a suspended 2FA enrolment answers as suspended;
 *   - no code answers as required;
 *   - past the soft cap the verifier does not run (no oracle for someone hammering codes) but the
 *     attempt still counts, so the hard threshold stays reachable;
 *   - a wrong code feeds the per-user TOTP throttle and NOTHING ELSE: never the account-lockout
 *     counters, so a mistyped code at this click cannot lock anyone out of signing in.
 *
 * What it does not claim: a TOTP code accepted here can be accepted again within its window (the
 * verifier has no replay protection); a recovery code is burned. It is "verified at the click",
 * not "unused".
 *
 * `$requireEnrolment`: a caller that always wants a second factor (the admin Erase) passes true,
 * and an account with none answers NotEnrolled instead of passing.
 */
final class SecondFactorStepUp
{
    public function __construct(
        private readonly TwoFactorChallengeService $challenge,
        private readonly TwoFactorVerificationThrottle $throttle,
    ) {}

    public function verify(User $user, #[SensitiveParameter] ?string $code, Request $request, bool $requireEnrolment = false): SecondFactorOutcome
    {
        if (! $user->hasTwoFactorEnabled()) {
            return $requireEnrolment ? SecondFactorOutcome::NotEnrolled : SecondFactorOutcome::Passed;
        }

        if ($user->hasTwoFactorEnrollmentSuspended()) {
            return SecondFactorOutcome::EnrollmentSuspended;
        }

        if ($code === null || trim($code) === '') {
            return SecondFactorOutcome::Required;
        }

        if ($this->throttle->isSoftLocked($user)) {
            $this->throttle->recordFailure($user, $request->ip(), $request->userAgent());

            return $user->refresh()->hasTwoFactorEnrollmentSuspended()
                ? SecondFactorOutcome::EnrollmentSuspended
                : SecondFactorOutcome::RateLimited;
        }

        if (! $this->challenge->verify($user, $code, $request)->passed) {
            $this->throttle->recordFailure($user, $request->ip(), $request->userAgent());

            return SecondFactorOutcome::Invalid;
        }

        $this->throttle->recordSuccess($user);

        return SecondFactorOutcome::Passed;
    }

    public function retryAfterSeconds(User $user): int
    {
        return $this->throttle->retryAfterSeconds($user);
    }
}
