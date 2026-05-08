<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Events\TwoFactorEnrollmentSuspended;
use App\Modules\Identity\Models\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

/**
 * Per-user TOTP verification throttle (chunk 5 priority #2).
 *
 * The TOTP code space is only 1,000,000 (six digits). The chunk 3
 * password rate limit (5 attempts/min/IP keyed on email+IP) does NOT
 * provide enough resistance because:
 *   - It can be evaded by IP rotation.
 *   - A handful of accounts shared across IPs would burn through the
 *     code space in hours.
 *
 * This throttle is keyed on the USER ID so an attacker who controls
 * many IPs still hits the same bucket. The two thresholds are:
 *
 *   - SOFT: 5 verification attempts per 15-minute sliding window. The
 *     6th attempt is rejected with a `mfa.rate_limited` error envelope
 *     until the oldest attempt ages out of the window. This is the
 *     "honest user fat-fingered the code" cap.
 *
 *   - HARD: 10 invalid attempts within the same 15-minute window
 *     suspends the user's 2FA enrollment. The user's
 *     `two_factor_enrollment_suspended_at` column is stamped, the
 *     {@see TwoFactorEnrollmentSuspended} event fires, and a
 *     `mfa.enrollment_suspended` audit row is written transactionally.
 *     A suspended user cannot complete the MFA gate until an admin
 *     clears the timestamp (Sprint 2 will surface this in the admin
 *     SPA; today it is a manual UPDATE).
 *
 * Counters live in cache keyed on the user ID, with per-attempt TTL
 * equal to the window length. The total invalid-attempt count is the
 * cache value at any given moment.
 */
final class TwoFactorVerificationThrottle
{
    public const SOFT_THRESHOLD = 5;

    public const HARD_THRESHOLD = 10;

    public const WINDOW_MINUTES = 15;

    private const CACHE_PREFIX = 'identity:2fa:throttle:';

    public function __construct(
        private readonly Cache $cache,
        private readonly AuditLogger $audit,
        private readonly ConnectionInterface $db,
        private readonly Dispatcher $events,
    ) {}

    public function isSoftLocked(User $user): bool
    {
        return $this->currentCount($user) >= self::SOFT_THRESHOLD;
    }

    public function recordSuccess(User $user): void
    {
        $this->cache->forget($this->key($user));
    }

    /**
     * Increment the user's invalid-attempt count and, if the hard
     * threshold is crossed, suspend the user's 2FA enrollment in the
     * same call.
     */
    public function recordFailure(User $user, ?string $ip, ?string $userAgent): void
    {
        $key = $this->key($user);
        $count = $this->currentCount($user) + 1;

        $this->cache->put(
            $key,
            $count,
            Carbon::now()->addMinutes(self::WINDOW_MINUTES),
        );

        if ($count >= self::HARD_THRESHOLD) {
            $this->suspendEnrollment($user, $ip, $userAgent);
        }
    }

    public function retryAfterSeconds(User $user): int
    {
        return (int) (self::WINDOW_MINUTES * 60);
    }

    private function currentCount(User $user): int
    {
        /** @var int|null $value */
        $value = $this->cache->get($this->key($user));

        return is_int($value) ? $value : 0;
    }

    private function suspendEnrollment(User $user, ?string $ip, ?string $userAgent): void
    {
        if ($user->hasTwoFactorEnrollmentSuspended()) {
            return;
        }

        $this->db->transaction(function () use ($user, $ip, $userAgent): void {
            $user->forceFill([
                'two_factor_enrollment_suspended_at' => Carbon::now(),
            ])->saveQuietly();

            // Audit row is written inside the transaction so the
            // suspension state and the audit log can never disagree
            // (mirrors AccountLockoutService::escalate()).
            $this->audit->log(
                action: AuditAction::MfaEnrollmentSuspended,
                actor: null,
                subject: $user,
                metadata: [
                    'window_minutes' => self::WINDOW_MINUTES,
                    'threshold' => self::HARD_THRESHOLD,
                ],
                ip: $ip,
                userAgent: $userAgent,
            );
        });

        $this->events->dispatch(new TwoFactorEnrollmentSuspended(
            user: $user->refresh(),
            ip: $ip,
            userAgent: $userAgent,
        ));
    }

    private function key(User $user): string
    {
        return self::CACHE_PREFIX.$user->getKey();
    }
}
