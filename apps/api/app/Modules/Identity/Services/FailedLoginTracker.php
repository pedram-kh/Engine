<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Carbon;

/**
 * Tracks failed login attempts per email so we can apply
 * docs/05-SECURITY-COMPLIANCE.md §6.2:
 *
 *   - 5 failures within 15 minutes  → temporary lockout (15-minute cooldown).
 *   - 10 failures within 24 hours   → escalation to permanent lockout
 *                                     (suspends the account; admin must
 *                                     un-suspend or the user must complete
 *                                     password reset).
 *
 * Storage is the application cache (Redis in production, array in tests).
 * We deliberately do not store on the User row itself: the value is
 * volatile, the cache is the right durability tier, and never having to
 * write to `users` from a failed-login codepath keeps that table cool.
 *
 * Keys live for 24 hours (the longest evaluation window). Successful
 * logins clear the per-email key — see {@see clear()}.
 */
final class FailedLoginTracker
{
    public const SHORT_WINDOW_MINUTES = 15;

    public const SHORT_WINDOW_THRESHOLD = 5;

    public const LONG_WINDOW_HOURS = 24;

    public const LONG_WINDOW_THRESHOLD = 10;

    private const CACHE_TTL_HOURS = 24;

    private const KEY_PREFIX = 'identity:failed-logins:';

    public function __construct(private readonly Cache $cache) {}

    /**
     * Record a failed attempt for the given email. Returns the in-window
     * counts so the caller can decide whether to lock or escalate without
     * a second cache hit.
     *
     * @return array{short_window_count: int, long_window_count: int}
     */
    public function record(string $email): array
    {
        $key = $this->keyFor($email);
        $now = Carbon::now()->getTimestamp();

        /** @var list<int> $existing */
        $existing = $this->cache->get($key, []);
        $existing[] = $now;
        $existing = $this->prune($existing);

        $this->cache->put($key, $existing, Carbon::now()->addHours(self::CACHE_TTL_HOURS));

        return [
            'short_window_count' => $this->countWithin($existing, self::SHORT_WINDOW_MINUTES * 60),
            'long_window_count' => count($existing),
        ];
    }

    public function shouldTemporarilyLock(string $email): bool
    {
        return $this->shortWindowCount($email) >= self::SHORT_WINDOW_THRESHOLD;
    }

    public function shouldEscalate(string $email): bool
    {
        return $this->longWindowCount($email) >= self::LONG_WINDOW_THRESHOLD;
    }

    public function shortWindowCount(string $email): int
    {
        return $this->countWithin($this->load($email), self::SHORT_WINDOW_MINUTES * 60);
    }

    public function longWindowCount(string $email): int
    {
        return count($this->load($email));
    }

    public function clear(string $email): void
    {
        $this->cache->forget($this->keyFor($email));
    }

    /**
     * @return list<int>
     */
    private function load(string $email): array
    {
        /** @var list<int> $existing */
        $existing = $this->cache->get($this->keyFor($email), []);

        return $this->prune($existing);
    }

    /**
     * @param  list<int>  $timestamps
     * @return list<int>
     */
    private function prune(array $timestamps): array
    {
        $cutoff = Carbon::now()->subHours(self::LONG_WINDOW_HOURS)->getTimestamp();

        return array_values(array_filter(
            $timestamps,
            static fn (int $ts): bool => $ts >= $cutoff,
        ));
    }

    /**
     * @param  list<int>  $timestamps
     */
    private function countWithin(array $timestamps, int $seconds): int
    {
        $cutoff = Carbon::now()->getTimestamp() - $seconds;

        return count(array_filter(
            $timestamps,
            static fn (int $ts): bool => $ts >= $cutoff,
        ));
    }

    private function keyFor(string $email): string
    {
        return self::KEY_PREFIX.strtolower(trim($email));
    }
}
