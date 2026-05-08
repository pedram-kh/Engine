<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Events\LoginFailed;
use App\Modules\Identity\Events\UserLoggedIn;
use App\Modules\Identity\Events\UserLoggedOut;
use App\Modules\Identity\Models\User;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

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
 *      a 423 with `auth.account_locked` even if the password is correct.
 *
 *   5. Hash rehash check via {@see Hash::needsRehash()} so cost-parameter
 *      changes propagate transparently on the user's next login. This is
 *      called in plaintext-still-in-memory window only.
 *
 *   6. MFA gate — if `users.two_factor_confirmed_at IS NOT NULL` we stop
 *      here with `auth.mfa_required`. The session is NOT attached. The
 *      MFA challenge endpoint (Sprint 1 chunk 5) will complete the login
 *      after a valid TOTP code. The branch is wired honestly today even
 *      though no users have 2FA in chunk 3.
 *
 *   7. Session attached via the configured guard. last_login_at /
 *      last_login_ip stamped. Failed-login counter cleared.
 *      {@see UserLoggedIn} emitted, listener writes audit row.
 */
final class AuthService
{
    public function __construct(
        private readonly AuthFactory $auth,
        private readonly Dispatcher $events,
        private readonly FailedLoginTracker $failedLogins,
        private readonly AccountLockoutService $lockout,
    ) {}

    public function login(string $email, string $password, Request $request, string $guard): LoginResult
    {
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

        if (Hash::needsRehash($user->password)) {
            $user->forceFill(['password' => Hash::make($password)])->saveQuietly();
        }

        if ($user->hasTwoFactorEnabled()) {
            $this->events->dispatch(new LoginFailed($email, $user, $ip, $userAgent, 'mfa_required'));

            return LoginResult::mfaRequired($user);
        }

        $this->failedLogins->clear($email);
        $this->lockout->clearTemporaryLock($email);

        $this->auth->guard($guard)->login($user);
        $request->session()->regenerate();

        $user->forceFill([
            'last_login_at' => Carbon::now(),
            'last_login_ip' => $ip,
        ])->saveQuietly();

        $this->events->dispatch(new UserLoggedIn($user, $ip, $userAgent, $guard));

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
}
