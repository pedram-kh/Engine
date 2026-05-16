<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Events\LoginFailed;
use App\Modules\Identity\Events\UserLoggedIn;
use App\Modules\Identity\Events\UserLoggedOut;
use App\Modules\Identity\Models\User;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;

/**
 * Owns the login + logout decision graph.
 *
 * Behaviour mirrors docs/04-API-DESIGN.md §4 + docs/05-SECURITY-COMPLIANCE.md §6.
 * Order of evaluation in {@see login()} is intentional and security-relevant:
 *
 *   1. Temporary lockout check — short-window throttle (5/15min).
 *      Returns 423 with `auth.account_locked.temporary` regardless of
 *      whether the password is correct. This denies an attacker the
 *      "did the password just become valid?" signal.
 *
 *   2. User lookup. If no user exists for the email, increment the
 *      counter and emit `auth.login.failed` against a null subject. We do
 *      not short-circuit early for unknown emails — that would let an
 *      attacker enumerate valid emails by timing differences. The Argon2id
 *      hash check below dominates the timing budget.
 *
 *   3. Password check using {@see Hash::check()}. Wrong password → record
 *      failure; if the per-email count crosses
 *      {@see FailedLoginTracker::SHORT_WINDOW_THRESHOLD} → temporary lock;
 *      if it crosses {@see FailedLoginTracker::LONG_WINDOW_THRESHOLD} →
 *      escalate to suspension.
 *
 *   4. Suspension check — reads `users.is_suspended`. Suspended users get
 *      a 423 with `auth.account_locked.suspended` even if the password is correct.
 *
 *   5. Hash rehash check via {@see Hash::needsRehash()} so cost-parameter
 *      changes propagate transparently on the user's next login. This is
 *      called in plaintext-still-in-memory window only.
 *
 *   6. SPA-mismatch gate — the (guard, user.type) pair must appear in
 *      {@see SPA_USER_TYPE_ALLOW_LIST}. A PlatformAdmin who supplies
 *      valid credentials on the agency SPA's `web` guard (or, mirrored,
 *      an AgencyUser / Creator on `web_admin`) is rejected with
 *      `auth.wrong_spa` 403 — no session attached, no rehash, no MFA
 *      challenge. The response carries the correct SPA URL in
 *      `meta.correct_spa_url` so the SPA can offer a one-click hop.
 *      This gate sits AFTER credential verification on purpose: we
 *      never reveal SPA-eligibility for an unknown email or a wrong
 *      password (which would be a soft enumeration oracle for the
 *      population of platform admins).
 *
 *   7. MFA gate — if `users.two_factor_confirmed_at IS NOT NULL` we stop
 *      here with `auth.mfa_required`. The session is NOT attached. The
 *      MFA challenge endpoint (Sprint 1 chunk 5) will complete the login
 *      after a valid TOTP code. The branch is wired honestly today even
 *      though no users have 2FA in chunk 3.
 *
 *   8. Session attached via the configured guard. last_login_at /
 *      last_login_ip stamped. Failed-login counter cleared.
 *      {@see UserLoggedIn} emitted, listener writes audit row.
 */
final class AuthService
{
    /**
     * Allow-list of user types per SPA guard. The agency SPA (`web`)
     * serves Creators (onboarding wizard + welcome-back flow) and
     * AgencyUsers (the role-stratified team UI). The admin SPA
     * (`web_admin`) serves PlatformAdmins exclusively. Any user type
     * not enumerated here for a given guard is treated as a SPA
     * mismatch and short-circuits with WrongSpa.
     *
     * `BrandUser` is reserved for Phase 2 (see
     * docs/20-PHASE-1-SPEC.md §3) and intentionally absent everywhere
     * — the schema knows about it, but no SPA serves it today, so a
     * brand-user attempting either side falls through to WrongSpa.
     *
     * Guards outside the SPA axis (e.g. `api`, `sanctum` token auth)
     * do not flow through this code path because the SPA login
     * controller hard-codes `web` / `web_admin` based on the matched
     * route name. If a future caller passes an unknown guard string,
     * we fail open (no gate) — that is the conservative posture for a
     * defensive check whose contract is "wrong SPA, not wrong auth".
     *
     * @var array<string, list<UserType>>
     */
    private const SPA_USER_TYPE_ALLOW_LIST = [
        'web' => [UserType::Creator, UserType::AgencyUser],
        'web_admin' => [UserType::PlatformAdmin],
    ];

    public function __construct(
        private readonly AuthFactory $auth,
        private readonly Dispatcher $events,
        private readonly FailedLoginTracker $failedLogins,
        private readonly AccountLockoutService $lockout,
        private readonly TwoFactorChallengeService $twoFactorChallenge,
        private readonly TwoFactorVerificationThrottle $twoFactorThrottle,
    ) {}

    public function login(
        string $email,
        #[SensitiveParameter] string $password,
        Request $request,
        string $guard,
        #[SensitiveParameter] ?string $mfaCode = null,
    ): LoginResult {
        $email = strtolower(trim($email));
        $ip = $request->ip();
        $userAgent = $request->userAgent();

        if ($this->lockout->isTemporarilyLocked($email)) {
            $this->events->dispatch(new LoginFailed($email, null, $ip, $userAgent, 'temporarily_locked'));

            return LoginResult::temporarilyLocked(FailedLoginTracker::SHORT_WINDOW_MINUTES * 60);
        }

        /** @var User|null $user */
        $user = User::query()->where('email', $email)->first();

        if (! $user instanceof User) {
            $this->recordFailureAndMaybeLock($email, null, $ip, $userAgent, 'unknown_email');

            return LoginResult::invalidCredentials();
        }

        if (! Hash::check($password, $user->password)) {
            $this->recordFailureAndMaybeLock($email, $user, $ip, $userAgent, 'invalid_password');

            // If the failure that just landed escalated the account, surface
            // the more accurate response code so the SPA tells the user
            // their account is locked rather than "wrong password".
            if ($user->refresh()->isSuspended()) {
                return LoginResult::accountSuspended($user);
            }

            if ($this->lockout->isTemporarilyLocked($email)) {
                return LoginResult::temporarilyLocked(FailedLoginTracker::SHORT_WINDOW_MINUTES * 60);
            }

            return LoginResult::invalidCredentials();
        }

        // Suspended check happens AFTER password verification on purpose:
        // we never want to confirm-or-deny suspension to anyone who
        // doesn't already know the password.
        if ($user->isSuspended()) {
            $this->events->dispatch(new LoginFailed($email, $user, $ip, $userAgent, 'account_suspended'));

            return LoginResult::accountSuspended($user);
        }

        // SPA-mismatch gate. Placed after credential + suspension
        // verification so a probe with a wrong password or against a
        // suspended account never leaks "this email belongs to a
        // platform admin" (a soft enumeration oracle). The session
        // attachment + rehash + MFA challenge below are all skipped on
        // a mismatch so the wrong-side flow has no side effects on the
        // user's account.
        if (! self::guardAcceptsUserType($guard, $user->type)) {
            $this->events->dispatch(new LoginFailed($email, $user, $ip, $userAgent, 'wrong_spa'));

            return LoginResult::wrongSpa($user);
        }

        if (Hash::needsRehash($user->password)) {
            $user->forceFill(['password' => Hash::make($password)])->saveQuietly();
        }

        $usedMfa = false;

        if ($user->hasTwoFactorEnabled()) {
            // 2FA enrollment can be administratively suspended after the
            // 10-invalid-attempts-in-15-minutes hard threshold trips. A
            // suspended user cannot complete the MFA gate even with a
            // valid code; admin must clear the timestamp.
            if ($user->hasTwoFactorEnrollmentSuspended()) {
                $this->events->dispatch(new LoginFailed($email, $user, $ip, $userAgent, 'mfa_enrollment_suspended'));

                return LoginResult::mfaEnrollmentSuspended($user);
            }

            if ($mfaCode === null || trim($mfaCode) === '') {
                $this->events->dispatch(new LoginFailed($email, $user, $ip, $userAgent, 'mfa_required'));

                return LoginResult::mfaRequired($user);
            }

            // When the soft-window cap is already reached we refuse to
            // run the verifier (no oracle for an attacker hammering codes)
            // BUT we still increment the counter for this attempt — the
            // hard threshold for full enrollment suspension must remain
            // reachable even by an attacker who paces themselves below
            // the soft-rate cap.
            if ($this->twoFactorThrottle->isSoftLocked($user)) {
                $this->twoFactorThrottle->recordFailure($user, $ip, $userAgent);
                $this->events->dispatch(new LoginFailed($email, $user, $ip, $userAgent, 'mfa_rate_limited'));

                if ($user->refresh()->hasTwoFactorEnrollmentSuspended()) {
                    return LoginResult::mfaEnrollmentSuspended($user);
                }

                return LoginResult::mfaRateLimited(
                    $user,
                    $this->twoFactorThrottle->retryAfterSeconds($user),
                );
            }

            $challenge = $this->twoFactorChallenge->verify($user, $mfaCode, $request);

            if (! $challenge->passed) {
                $this->twoFactorThrottle->recordFailure($user, $ip, $userAgent);
                $this->events->dispatch(new LoginFailed($email, $user, $ip, $userAgent, 'mfa_invalid_code'));

                // No "did the failure I just recorded push me past the hard
                // threshold?" check here. Under current thresholds (soft=5,
                // hard=10) that path is unreachable from this branch — the
                // user would have been soft-locked above before the challenge
                // ever ran. The soft-locked branch above does the suspension
                // check transactionally for any future threshold tuning.
                return LoginResult::mfaInvalidCode($user);
            }

            $this->twoFactorThrottle->recordSuccess($user);
            $usedMfa = true;
        }

        $this->failedLogins->clear($email);
        $this->lockout->clearTemporaryLock($email);

        $this->auth->guard($guard)->login($user);
        $request->session()->regenerate();

        $user->forceFill([
            'last_login_at' => Carbon::now(),
            'last_login_ip' => $ip,
        ])->saveQuietly();

        $this->events->dispatch(new UserLoggedIn(
            user: $user,
            ip: $ip,
            userAgent: $userAgent,
            guard: $guard,
            mfa: $usedMfa,
        ));

        return LoginResult::success($user);
    }

    public function logout(Request $request, string $guard): void
    {
        /** @var User|null $user */
        $user = $this->auth->guard($guard)->user();

        $this->auth->guard($guard)->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        if ($user instanceof User) {
            $this->events->dispatch(new UserLoggedOut(
                user: $user,
                ip: $request->ip(),
                userAgent: $request->userAgent(),
                guard: $guard,
            ));
        }
    }

    private function recordFailureAndMaybeLock(
        string $email,
        ?User $user,
        ?string $ip,
        ?string $userAgent,
        string $reason,
    ): void {
        $counts = $this->failedLogins->record($email);

        $this->events->dispatch(new LoginFailed($email, $user, $ip, $userAgent, $reason));

        if ($user instanceof User
            && $counts['long_window_count'] >= FailedLoginTracker::LONG_WINDOW_THRESHOLD
        ) {
            $this->lockout->escalate($user);

            return;
        }

        if ($counts['short_window_count'] >= FailedLoginTracker::SHORT_WINDOW_THRESHOLD) {
            $this->lockout->temporaryLock($email);
        }
    }

    /**
     * Returns true if the SPA reachable via `$guard` is expected to
     * serve a user of `$type`. Unknown guards (anything outside the
     * `web` / `web_admin` SPA pair) fail open — the WrongSpa gate is
     * specifically about the two-SPA topology and should not interfere
     * with API-token flows that may land here in the future.
     */
    private static function guardAcceptsUserType(string $guard, UserType $type): bool
    {
        $allowed = self::SPA_USER_TYPE_ALLOW_LIST[$guard] ?? null;
        if ($allowed === null) {
            return true;
        }

        return in_array($type, $allowed, true);
    }
}
