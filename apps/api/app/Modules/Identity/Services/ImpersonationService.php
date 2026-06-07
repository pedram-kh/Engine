<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Exceptions\ImpersonationException;
use App\Modules\Identity\Models\ImpersonationSession;
use App\Modules\Identity\Models\User;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Owns the impersonation lifecycle (Sprint 13, D-9).
 *
 * Three seams, deliberately split across the two-cookie boundary:
 *
 *   - start()  — runs on the ADMIN session (`web_admin`). Validates the
 *                no-escalation rules, writes the authoritative
 *                admin_impersonation_sessions row (the TTL authority), audits
 *                `admin.impersonation_started`, and mints a one-time hand-off
 *                token. The admin session is NEVER touched.
 *   - claim()  — runs on the MAIN session (`web`). Consumes the one-time
 *                token, logs the impersonated user into the `web` guard, and
 *                pins the session ulid into the main session. SINGLE-USE.
 *   - end()    — terminates the row; the enforcement middleware tears the
 *                main session down on its next request (server-authoritative).
 *
 * The TTL (Q2) lives ONLY on the row's `expires_at`; nothing here trusts a
 * client clock. No-escalation (Q-D9): start() refuses to target a
 * platform_admin or to target the admin themselves, and the impersonated
 * `web` session can never reach the `web_admin`-guarded start endpoint, so
 * nesting is structurally impossible.
 */
final class ImpersonationService
{
    /**
     * The impersonated session's absolute TTL (Pedram-confirmed, Q2).
     */
    public const TTL_MINUTES = 30;

    /**
     * The main `web` session key that pins the live impersonation ulid. The
     * enforcement middleware reads this on every main-SPA request.
     */
    public const SESSION_KEY = 'impersonation_session_ulid';

    public function __construct(
        private readonly AuthFactory $auth,
    ) {}

    /**
     * Start an impersonation from the admin session. Returns the row + the
     * one-time plaintext hand-off token (shown to the admin once).
     *
     * @return array{session: ImpersonationSession, token: string}
     *
     * @throws ImpersonationException
     */
    public function start(User $admin, User $target, string $reason, ?string $ip): array
    {
        // Self before admin: an admin targeting themselves should hear the
        // specific reason, not the generic "can't target an admin".
        if ($target->getKey() === $admin->getKey()) {
            throw ImpersonationException::cannotImpersonateSelf();
        }

        if ($target->type === UserType::PlatformAdmin) {
            throw ImpersonationException::cannotImpersonateAdmin();
        }

        // No-nesting (Q-D9): an admin may hold at most one live impersonation
        // at a time. A second start while one is still active (unexpired,
        // unended) is refused — the admin must end the first explicitly. This
        // keeps the "who is this admin currently acting as" fact singular and
        // unambiguous for incident review.
        $hasActive = ImpersonationSession::query()
            ->where('admin_user_id', $admin->getKey())
            ->whereNull('ended_at')
            ->where('expires_at', '>', Carbon::now())
            ->exists();

        if ($hasActive) {
            throw ImpersonationException::alreadyImpersonating();
        }

        $now = Carbon::now();
        $plainToken = Str::random(64);

        $session = DB::transaction(function () use ($admin, $target, $reason, $ip, $now, $plainToken): ImpersonationSession {
            $session = ImpersonationSession::query()->create([
                'admin_user_id' => $admin->getKey(),
                'impersonated_user_id' => $target->getKey(),
                'reason' => $reason,
                'token_hash' => self::hashToken($plainToken),
                'expires_at' => $now->copy()->addMinutes(self::TTL_MINUTES),
                'started_at' => $now,
                'ip' => $ip,
                'created_at' => $now,
            ]);

            Audit::log(
                action: AuditAction::AdminImpersonationStarted,
                actor: $admin,
                subject: $target,
                reason: $reason,
                metadata: [
                    'impersonation_ulid' => $session->ulid,
                    'impersonated_user_id' => $target->getKey(),
                ],
            );

            return $session;
        });

        return ['session' => $session, 'token' => $plainToken];
    }

    /**
     * Consume a one-time hand-off token on the MAIN session and log the
     * impersonated user into the `web` guard. SINGLE-USE: the token is
     * nulled out on success so it can never be replayed.
     *
     * @throws ImpersonationException
     */
    public function claim(string $plainToken, Request $request): ImpersonationSession
    {
        /** @var ImpersonationSession|null $session */
        $session = ImpersonationSession::query()
            ->where('token_hash', self::hashToken($plainToken))
            ->first();

        if ($session === null || $session->claimed_at !== null || ! $session->isActive()) {
            throw ImpersonationException::invalidHandoff();
        }

        /** @var User|null $target */
        $target = User::query()->find($session->impersonated_user_id);
        if ($target === null) {
            throw ImpersonationException::invalidHandoff();
        }

        return DB::transaction(function () use ($session, $target, $request): ImpersonationSession {
            $this->auth->guard('web')->login($target);
            $request->session()->regenerate();
            $request->session()->put(self::SESSION_KEY, $session->ulid);

            // Single-use: burn the token + stamp the claim. forceFill avoids
            // the mass-assignment guard on the lifecycle columns.
            $session->forceFill([
                'claimed_at' => Carbon::now(),
                'token_hash' => null,
            ])->save();

            return $session;
        });
    }

    /**
     * End the admin's currently-active impersonation (from the admin tab).
     * Audits `admin.impersonation_ended`. The main session is torn down by
     * the enforcement middleware on its next request (server-authoritative).
     */
    public function endForAdmin(User $admin): ?ImpersonationSession
    {
        /** @var ImpersonationSession|null $session */
        $session = ImpersonationSession::query()
            ->where('admin_user_id', $admin->getKey())
            ->whereNull('ended_at')
            ->where('expires_at', '>', Carbon::now())
            ->orderByDesc('id')
            ->first();

        if ($session === null) {
            return null;
        }

        $this->terminate($session, $admin);

        return $session;
    }

    /**
     * End an impersonation from the impersonated (main) tab and log the
     * `web` guard out cleanly, returning to a normal anonymous main session.
     */
    public function endFromMain(Request $request): void
    {
        $ulid = $request->session()->get(self::SESSION_KEY);

        if (is_string($ulid)) {
            /** @var ImpersonationSession|null $session */
            $session = ImpersonationSession::query()->where('ulid', $ulid)->first();
            if ($session !== null && $session->ended_at === null) {
                $this->terminate($session, $session->admin);
            }
        }

        $this->tearDownMainSession($request);
    }

    /**
     * Log the impersonated user out of the `web` guard and shred the main
     * session, returning the tab to a clean anonymous state. Used both by an
     * explicit end and by the enforcement middleware when it rejects an
     * expired / orphaned impersonation (the server-authoritative break).
     */
    public function tearDownMainSession(Request $request): void
    {
        $this->auth->guard('web')->logout();
        $request->session()->forget(self::SESSION_KEY);
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    /**
     * Mark a session ended + write the end audit row. Idempotent on the
     * row (a second call is a no-op once ended_at is set).
     */
    public function terminate(ImpersonationSession $session, ?User $admin): void
    {
        if ($session->ended_at !== null) {
            return;
        }

        DB::transaction(function () use ($session, $admin): void {
            $session->forceFill(['ended_at' => Carbon::now()])->save();

            Audit::log(
                action: AuditAction::AdminImpersonationEnded,
                actor: $admin,
                subject: $session->impersonatedUser,
                metadata: [
                    'impersonation_ulid' => $session->ulid,
                    'impersonated_user_id' => $session->impersonated_user_id,
                ],
            );
        });
    }

    public function resolveImpersonatedUser(ImpersonationSession $session): User
    {
        /** @var User $user */
        $user = User::query()->findOrFail($session->impersonated_user_id);

        return $user;
    }

    private static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }
}
