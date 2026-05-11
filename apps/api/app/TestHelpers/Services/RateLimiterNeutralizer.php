<?php

declare(strict_types=1);

namespace App\TestHelpers\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Cache-backed registry of "neutralised" named rate limiters used by the
 * Playwright E2E suite (chunk 7.1 priority — restores spec #20).
 *
 * Why this exists
 * ---------------
 * The chunk-3 throttle layer (`auth-ip`, `auth-login-email`,
 * `auth-password`, `auth-resend-verification`) preempts the chunk-3 / 5
 * application-level lockout layer (`FailedLoginTracker` /
 * `AccountLockoutService`) at the same threshold for the login flow.
 * The chunk-5 Pest suite hides this overlap by registering the named
 * limiters as `Limit::none()` in `LoginTest::beforeEach` so the
 * lockout layer can be exercised in isolation; spec #20 needs the same
 * shape from a Playwright-driven request, so this service makes the
 * "neutralise this named limiter" toggle survive across the per-request
 * PHP processes that `php artisan serve` spawns.
 *
 * Persistence model
 * -----------------
 * The set of currently-neutralised names is stored as a single cache
 * entry under {@see CACHE_KEY}, holding a `list<string>`. We deliberately
 * use ONE composite key rather than per-name flags so the
 * {@see TestHelpersServiceProvider::boot()} re-application loop is a
 * single cache read per request instead of N cache reads, and so any
 * cache backend (array, database, Redis) works without driver-specific
 * key-walking.
 *
 * Allowlist invariant
 * -------------------
 * Only names in {@see ALLOWED_NAMES} can be neutralised — the four named
 * limiters {@see App\Modules\Identity\IdentityServiceProvider::registerRateLimits()}
 * registers. Adding a new limiter on the production side requires an
 * explicit add here too; that's intentional, so a typo in a spec
 * cannot silently turn into "no limiters neutralised" or "wrong
 * limiter neutralised".
 *
 * Mandatory restore-after-test convention
 * ---------------------------------------
 * Every spec that calls {@see neutralize()} MUST pair it with a
 * matching {@see restore()} in `afterEach`. The state lives in shared
 * cache and survives across tests; an un-restored neutraliser would
 * silently bleed into every subsequent spec on the same suite run.
 * The Playwright fixtures (`neutralizeThrottle` / `restoreThrottle`)
 * enforce the pair at the call-site by matching naming.
 */
final class RateLimiterNeutralizer
{
    /**
     * Single composite cache key holding the `list<string>` of
     * currently-neutralised limiter names. The `test:` prefix matches
     * the convention {@see App\TestHelpers\Services\TestClock} uses, so
     * a stray operator inspecting Redis sees at a glance these are
     * test-only debug knobs.
     */
    public const CACHE_KEY = 'test:rate-limiter:neutralized';

    /**
     * Limiter names a spec is allowed to neutralise. Mirrors the four
     * `RateLimiter::for(...)` registrations in IdentityServiceProvider.
     * If a future production limiter is added, an explicit entry here
     * is required before specs can target it — a typo or an attempt
     * to neutralise a non-existent limiter returns 422 (see the
     * controller).
     */
    public const ALLOWED_NAMES = [
        'auth-ip',
        'auth-login-email',
        'auth-password',
        'auth-resend-verification',
    ];

    public function __construct(private readonly CacheRepository $cache) {}

    /**
     * Mark `$name` as neutralised. Idempotent. Caller is responsible
     * for ensuring `$name` is in {@see ALLOWED_NAMES}; the controller
     * layer enforces this before delegating.
     */
    public function neutralize(string $name): void
    {
        $current = $this->list();

        if (! in_array($name, $current, true)) {
            $current[] = $name;
        }

        $this->cache->forever(self::CACHE_KEY, array_values($current));
    }

    /**
     * Remove `$name` from the neutralised set. Idempotent — restoring
     * a name that was never neutralised is a no-op.
     */
    public function restore(string $name): void
    {
        $current = array_values(array_filter(
            $this->list(),
            static fn (string $entry): bool => $entry !== $name,
        ));

        if ($current === []) {
            $this->cache->forget(self::CACHE_KEY);

            return;
        }

        $this->cache->forever(self::CACHE_KEY, $current);
    }

    public function isNeutralized(string $name): bool
    {
        return in_array($name, $this->list(), true);
    }

    /**
     * The full list of currently-neutralised names. Read by
     * {@see TestHelpersServiceProvider::boot()} every request so the
     * named-limiter overrides survive `php artisan serve`'s per-request
     * PHP-process model.
     *
     * Cache-backend defence
     * ---------------------
     * `list()` is the cache-touching method on the boot path, and
     * `boot()` runs before any artisan command — including
     * `key:generate` and `migrate`. Composer's post-install hook
     * triggers `key:generate` before `migrate:fresh` runs, so under a
     * `database` cache driver the `cache` table does not exist yet
     * the first time this method is reached. Cache backends can also
     * be unreachable for other reasons (Redis down, file path missing,
     * etc.). We catch `\Throwable` and return `[]` rather than
     * propagate, on the principle that "no overrides apply this
     * request" is the safe default — production throttle behaviour
     * stays in place, and a spec that expected neutralisation will
     * fail at the assertion layer (its 429 won't downgrade to 423),
     * surfacing the cache problem there rather than in the boot path.
     *
     * No logging deliberately: the apply-loop runs on every request
     * under `php artisan serve`'s per-request PHP-process model, so a
     * log line per request when cache is down would be noise. The
     * test-layer failure is the right diagnostic surface.
     *
     * @return list<string>
     */
    public function list(): array
    {
        try {
            $value = $this->cache->get(self::CACHE_KEY, []);
        } catch (\Throwable) {
            return [];
        }

        if (! is_array($value)) {
            return [];
        }

        // Defensive: filter out anything that isn't a string and any
        // entry not in the allowlist. A corrupted value should not
        // propagate into RateLimiter::for() — better to drop the
        // unknown name silently than to register an override on a
        // limiter we don't control.
        return array_values(array_filter(
            $value,
            static fn (mixed $entry): bool => is_string($entry)
                && in_array($entry, self::ALLOWED_NAMES, true),
        ));
    }

    /**
     * Clear all neutralised entries. Exposed for test teardown so the
     * Pest suite can reset between groups without relying on the cache
     * driver's flush semantics.
     */
    public function clear(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }
}
