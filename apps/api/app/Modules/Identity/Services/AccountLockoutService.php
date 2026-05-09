<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Events\AccountLocked;
use App\Modules\Identity\Models\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Carbon;

/**
 * Owns the lock/escalate decision and the side-effects that follow.
 *
 *   - {@see escalate()}     hard-suspends the user (`is_suspended=true`,
 *                           `suspended_reason='Excessive failed login attempts'`,
 *                           `suspended_at=now`), audits, and emits the
 *                           {@see AccountLocked} event.
 *   - {@see temporaryLock()} stores a 15-minute cooldown key so the next
 *                           login attempt for that email (even with the
 *                           correct password) is rejected with
 *                           `auth.account_locked.temporary` until expiry.
 *   - {@see isTemporarilyLocked()} reads the cooldown key.
 *   - {@see clearTemporaryLock()} clears it on a successful login or
 *                           password reset.
 *
 * The hard-suspend path only fires once per breach: subsequent attempts
 * resolve through the `is_suspended` short-circuit in
 * {@see AuthService} and do not re-emit
 * the event or rewrite the row.
 */
final class AccountLockoutService
{
    public const ESCALATION_REASON = 'Excessive failed login attempts';

    private const TEMPORARY_LOCK_KEY_PREFIX = 'identity:temporary-lock:';

    public function __construct(
        private readonly Cache $cache,
        private readonly AuditLogger $audit,
        private readonly Dispatcher $events,
    ) {}

    public function temporaryLock(string $email): void
    {
        $this->cache->put(
            $this->keyFor($email),
            true,
            Carbon::now()->addMinutes(FailedLoginTracker::SHORT_WINDOW_MINUTES),
        );
    }

    public function isTemporarilyLocked(string $email): bool
    {
        return (bool) $this->cache->get($this->keyFor($email), false);
    }

    public function clearTemporaryLock(string $email): void
    {
        $this->cache->forget($this->keyFor($email));
    }

    /**
     * Hard-suspend the user and audit the action. Idempotent: re-running
     * against an already-suspended user does nothing.
     */
    public function escalate(User $user): void
    {
        if ($user->isSuspended()) {
            return;
        }

        $user->forceFill([
            'is_suspended' => true,
            'suspended_reason' => self::ESCALATION_REASON,
            'suspended_at' => Carbon::now(),
        ])->saveQuietly();

        $this->audit->log(
            action: AuditAction::AuthAccountLockedSuspended,
            subject: $user,
            reason: self::ESCALATION_REASON,
            metadata: [
                'window_hours' => FailedLoginTracker::LONG_WINDOW_HOURS,
                'threshold' => FailedLoginTracker::LONG_WINDOW_THRESHOLD,
            ],
        );

        $this->events->dispatch(new AccountLocked($user, self::ESCALATION_REASON));
    }

    private function keyFor(string $email): string
    {
        return self::TEMPORARY_LOCK_KEY_PREFIX.strtolower(trim($email));
    }
}
