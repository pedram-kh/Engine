<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Core\Tenancy\SetTenancyContext;
use App\Modules\Agencies\Models\AgencyMembership;
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
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;
use RuntimeException;
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
    /** The guard / allow-list key the token door evaluates under (AH-099). */
    public const TOKEN_GUARD = 'creator_app';

    /** Per-device token name prefix: `creator-app:{ios|android}`. */
    public const TOKEN_NAME_PREFIX = 'creator-app:';

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
     * `creator_app` is the token door ({@see issueToken()}, AH-099) — it
     * evaluates this same graph under its own key. Guards outside this
     * set do not flow through here. If a future caller passes an unknown guard string,
     * we fail open (no gate) — that is the conservative posture for a
     * defensive check whose contract is "wrong SPA, not wrong auth".
     *
     * @var array<string, list<UserType>>
     */
    private const SPA_USER_TYPE_ALLOW_LIST = [
        'web' => [UserType::Creator, UserType::AgencyUser],
        'web_admin' => [UserType::PlatformAdmin],
        // AH-099: the token door. Creator-only by design — agency members and
        // admins get no app token (this arc is the creator app).
        'creator_app' => [UserType::Creator],
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
        // Defensive guard: login needs a session to attach the
        // authenticated user to (see step 8 below — `auth->login($user)`
        // + `$request->session()->regenerate()`). Sanctum's
        // EnsureFrontendRequestsAreStateful normally appends StartSession
        // on requests from a configured SANCTUM_STATEFUL_DOMAINS origin;
        // when the browser host is not in the stateful list (e.g.
        // `localhost:5173` against a 127.0.0.1-only list), no session is
        // bound and `regenerate()` blows up with an inscrutable
        // RuntimeException at the call site. Convert that into a clearly-
        // named log line + exception so the diagnostic surfaces in one
        // grep rather than via a 50-frame trace. Mirrors the equivalent
        // `hasSession()` guard already in `logout()`.
        if (! $request->hasSession()) {
            // Log event uses the subsystem prefix (`identity.*`) — parallel
            // to PwnedPasswordsClient's `hibp.*` — so the SPA's i18n
            // architecture test (tests/unit/architecture/i18n-auth-codes.spec.ts)
            // does NOT harvest it as a user-facing translation key. This is
            // a server-config diagnostic that never reaches a creator's
            // banner; the throw below 500s the request with Laravel's
            // generic exception envelope.
            Log::error('identity.login.no_session_bound_on_request', [
                'host' => $request->getHttpHost(),
                'referer' => $request->headers->get('referer'),
                'origin' => $request->headers->get('origin'),
                'session_cookie' => config('session.cookie'),
            ]);

            throw new RuntimeException(
                'Login attempted on a request with no session bound. Likely a '
                .'Sanctum stateful-domain misconfiguration: verify the browser '
                .'host is in SANCTUM_STATEFUL_DOMAINS (see docs/runbooks/local-dev.md).',
            );
        }

        $gates = $this->evaluateGates($email, $password, $request, $guard, $mfaCode);

        if ($gates instanceof LoginResult) {
            return $gates;
        }

        $email = $gates->email;
        $user = $gates->user;
        $ip = $gates->ip;
        $userAgent = $gates->userAgent;
        $usedMfa = $gates->usedMfa;

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

    /**
     * Token door (AH-099, D1): the creator app's login. Runs the SAME gate
     * graph as {@see login()} via {@see evaluateGates()} and differs only at
     * the very end — a personal access token is minted and returned instead
     * of `guard()->login()` + session regenerate. Consequently:
     *
     *   - 2FA holds: an enrolled creator gets `MfaRequired` exactly as on
     *     web, and no token exists until a valid code is supplied;
     *   - failed attempts feed the same lockout counters as the web door;
     *   - no session is required or touched (the Capacitor origin is not a
     *     stateful domain, so none is bound) — hence no `hasSession()` guard
     *     here, unlike {@see login()}.
     *
     * Creator-only: the allow-list key `creator_app` admits `Creator` and
     * nothing else, so an agency member or platform admin with valid
     * credentials gets `WrongSpa` and no token. That is the deliberate
     * boundary of this arc (the creator app), not an oversight.
     *
     * Impersonation cannot mint a token: minting requires the account's
     * password, which an impersonating admin never has.
     *
     * @param  'ios'|'android'  $platform
     */
    public function issueToken(
        string $email,
        #[SensitiveParameter] string $password,
        Request $request,
        string $platform,
        #[SensitiveParameter] ?string $mfaCode = null,
    ): LoginResult {
        $gates = $this->evaluateGates($email, $password, $request, self::TOKEN_GUARD, $mfaCode);

        if ($gates instanceof LoginResult) {
            return $gates;
        }

        $this->failedLogins->clear($gates->email);
        $this->lockout->clearTemporaryLock($gates->email);

        $token = $gates->user->createToken(self::TOKEN_NAME_PREFIX.$platform, ['*']);

        $gates->user->forceFill([
            'last_login_at' => Carbon::now(),
            'last_login_ip' => $gates->ip,
        ])->saveQuietly();

        $this->events->dispatch(new UserLoggedIn(
            user: $gates->user,
            ip: $gates->ip,
            userAgent: $gates->userAgent,
            guard: self::TOKEN_GUARD,
            mfa: $gates->usedMfa,
        ));

        return LoginResult::success($gates->user, $token);
    }

    public function logout(Request $request, string $guard): void
    {
        /** @var User|null $user */
        $user = $this->auth->guard($guard)->user();

        // AH-099 token door: a bearer request has no session user on the
        // `web` guard, only a `creator_app` user. Revoke exactly the token
        // presented — the creator's other devices stay signed in. The cookie
        // branch below is unchanged and still taken for every session user.
        if ($user === null && $guard === 'web') {
            $tokenUser = $this->auth->guard(self::TOKEN_GUARD)->user();

            if ($tokenUser instanceof User) {
                $this->logoutToken($request, $tokenUser);

                return;
            }
        }

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

    /**
     * The credential + gate graph shared by BOTH login doors (AH-099, D1):
     * lockout → lookup → password → suspension → agency suspension →
     * SPA/door gate → rehash → 2FA. Extracted from {@see login()} with the
     * body moved verbatim, so the session door and the token door
     * ({@see issueToken()}) can never drift: there is one graph, and the two
     * doors differ only in what they do once it has passed.
     *
     * Returns a failure {@see LoginResult} the moment any gate trips, or
     * {@see GatesPassed} when every gate cleared (credentials verified, 2FA
     * satisfied where enrolled). No session or token is touched in here.
     */
    private function evaluateGates(
        string $email,
        #[SensitiveParameter] string $password,
        Request $request,
        string $guard,
        #[SensitiveParameter] ?string $mfaCode,
    ): LoginResult|GatesPassed {
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

        // AH-104a — an erased account (anonymised by the erasure engine) can never
        // sign in. It is answered EXACTLY like an unknown email, before the password
        // is checked, so no response, status or audit reason distinguishes "erased"
        // from "never existed". This is the one guard both doors share: the SPA
        // login() and the native issueToken() both run this graph. The engine also
        // randomises the password and replaces the email, so this is defence in depth.
        if ($user->isErased()) {
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

        // Agency-suspension gate (Sprint 13, D-3). Reuses the same
        // security-ordered login graph rather than a separate middleware:
        // a second place where account-locking logic lives would be a
        // drift risk (Q1). Placed right after the per-user suspension
        // check, on the same "credentials already verified" footing.
        //
        // We block ONLY on the user's PRIMARY (acting) agency — the first
        // accepted membership by id, mirroring SetTenancyContext's
        // primary-membership convention. A user who belongs to multiple
        // agencies is NOT locked out because some OTHER, unrelated agency
        // is suspended; only a suspension of the agency they actually act
        // under denies the session (Q1, the deliberate multi-agency case).
        //
        // The login graph is reused so the result is the same no-session,
        // suspended-style envelope the per-user path returns; the distinct
        // LoginFailed reason `agency_suspended` keeps the trail legible.
        // platform_admins / creators have no agency membership, so this is
        // a structural no-op for them.
        if ($this->primaryAgencyIsSuspended($user)) {
            $this->events->dispatch(new LoginFailed($email, $user, $ip, $userAgent, 'agency_suspended'));

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

        return new GatesPassed($email, $user, $ip, $userAgent, $usedMfa);
    }

    private function logoutToken(Request $request, User $user): void
    {
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        $this->events->dispatch(new UserLoggedOut(
            user: $user,
            ip: $request->ip(),
            userAgent: $request->userAgent(),
            guard: self::TOKEN_GUARD,
        ));
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
     * True when the user's PRIMARY (acting) agency is suspended (D-3).
     *
     * "Primary" mirrors {@see SetTenancyContext}: the
     * first accepted membership ordered by id — the agency the user would
     * act under once tenancy resolves. We require `accepted_at` so a
     * dangling pending invitation to a suspended agency never blocks a
     * login (an invite is not an acting relationship).
     *
     * Only this primary agency gates the login. A user with a second,
     * healthy membership is NOT denied because some unrelated agency they
     * also belong to is suspended (the deliberate multi-agency case, Q1).
     *
     * The `agency` relation is eager-loaded so the suspension read is a
     * single extra query; `withTrashed` is NOT used — a soft-deleted
     * agency has no live users to log in.
     */
    private function primaryAgencyIsSuspended(User $user): bool
    {
        $membership = AgencyMembership::query()
            ->where('user_id', $user->getKey())
            ->whereNotNull('accepted_at')
            ->orderBy('id')
            ->with('agency')
            ->first();

        return $membership?->agency?->isSuspended() ?? false;
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
