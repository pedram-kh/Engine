<?php

declare(strict_types=1);

namespace App\TestHelpers\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Carbon;

/**
 * Redis-backed test clock used by the Playwright E2E suite.
 *
 * The Playwright spec for "failed-login lockout + reset" (chunk 6
 * priority #20) needs to span 24 hours of real time to exercise the
 * long-window lockout escalation. Burning that wall clock in CI is
 * impractical, so this service stores a "test now" timestamp in the
 * application cache (Redis in real environments, array driver in
 * tests), and the {@see App\TestHelpers\Http\Middleware\ApplyTestClock}
 * middleware reads it on every request and calls Carbon::setTestNow()
 * before any application code runs.
 *
 * The persistence step is what makes this work across the request
 * boundary. Carbon::setTestNow is a process-local global; setting it
 * inside a request would not propagate to the next one. By stashing
 * the value in shared cache and replaying it via middleware, every
 * subsequent request the spec issues lands on the same simulated
 * clock until the spec resets it.
 *
 * The service is intentionally side-effect free w.r.t. Carbon — the
 * MIDDLEWARE applies the clock, this service only persists. That
 * separation lets us unit-test the persistence and the middleware
 * independently and keeps "what does the request see?" a property of
 * one place.
 */
final class TestClock
{
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly ConfigRepository $config,
    ) {}

    public function set(Carbon $at): void
    {
        // No TTL: the clock survives across many requests (a Playwright
        // spec may issue dozens of API calls under the same simulated
        // clock). The reset endpoint or a test teardown is responsible
        // for clearing it.
        $this->cache->forever($this->key(), $at->toIso8601String());
    }

    public function reset(): void
    {
        $this->cache->forget($this->key());
    }

    public function current(): ?Carbon
    {
        $value = $this->cache->get($this->key());

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            // Defensive: a corrupted value should not crash every
            // request. We log nothing and return null so the request
            // proceeds on real time. The reset endpoint clears
            // garbage out of band.
            return null;
        }
    }

    private function key(): string
    {
        return (string) $this->config->get('test_helpers.clock_cache_key', 'test:clock:current');
    }
}
