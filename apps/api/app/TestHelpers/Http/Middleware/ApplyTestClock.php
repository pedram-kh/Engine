<?php

declare(strict_types=1);

namespace App\TestHelpers\Http\Middleware;

use App\TestHelpers\Services\TestClock;
use App\TestHelpers\TestHelpersServiceProvider;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reads the Redis-backed test clock on every request and replays it
 * onto Carbon so every Carbon::now() call inside the request sees the
 * simulated time. The chunk-6 Playwright lockout spec depends on this
 * to fast-forward the 24-hour failed-login window without burning
 * actual wall-clock time in CI.
 *
 * Belt-and-suspenders gating:
 *   - {@see TestHelpersServiceProvider::boot()} only PUSHES this
 *     middleware onto the global stack when the gate is open at boot
 *     (env in local/testing AND token configured). Production stacks
 *     never see it.
 *   - This handler also re-checks {@see TestHelpersServiceProvider::gateOpen()}
 *     per request so a runtime config flip (e.g. tests overriding
 *     `test_helpers.token` to '') closes the clock too. That keeps the
 *     route gate and the clock gate in lock-step.
 *
 * Carbon state management: on every gate-open request, ApplyTestClock
 * reads the cached clock and writes it through to Carbon::setTestNow()
 * if the cache is non-empty. If the cache is empty AND this middleware
 * previously pinned Carbon, it resets Carbon to real wall-clock time.
 * The "previously pinned" check is what makes the reset deterministic
 * across the request boundary in process-reused contexts (`php artisan
 * serve`, Octane, the long-lived API container the Playwright runner
 * targets) — a `POST /_test/clock/reset` -> next-request sequence
 * cleanly clears the simulated clock.
 *
 * Why we do NOT call `Carbon::setTestNow($cache->get(key))`
 * unconditionally: the unconditional call would clobber a Pest test
 * that pins Carbon directly via `Carbon::setTestNow(X)` in its body
 * before issuing an API request (e.g. `LoginTest` exercises the
 * 24-hour lockout escalation by fast-forwarding Carbon between
 * requests). We want this middleware to be a no-op when the test-
 * helpers surface is unused, regardless of what other code is doing
 * with Carbon. Tracking our own pinning via a static flag preserves
 * that contract.
 *
 * `resetPinningTracker()` is exposed for `Tests\TestCase::tearDown()`
 * to clear the static flag between tests so process-reused state does
 * not bleed across the Pest suite.
 */
final class ApplyTestClock
{
    /**
     * True iff THIS middleware pinned Carbon::setTestNow on a prior
     * request and has not yet cleared it. Process-global, lifecycle
     * tied to Carbon's own static state; reset between Pest tests via
     * {@see resetPinningTracker()}.
     */
    private static bool $pinnedByModule = false;

    public function __construct(private readonly TestClock $clock) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! TestHelpersServiceProvider::gateOpen()) {
            return $next($request);
        }

        $at = $this->clock->current();

        if ($at instanceof Carbon) {
            Carbon::setTestNow($at);
            self::$pinnedByModule = true;
        } elseif (self::$pinnedByModule) {
            // Cache is empty AND we previously pinned Carbon — this is
            // the set-then-reset sequence the leak-regression test
            // exercises. Clear Carbon and the tracker.
            Carbon::setTestNow();
            self::$pinnedByModule = false;
        }
        // else: cache is empty AND we never pinned. A Pest test may
        // have called Carbon::setTestNow() directly; the test owns the
        // teardown and we MUST NOT touch Carbon here.

        return $next($request);
    }

    /**
     * Reset the static pinning tracker. Called from
     * {@see Tests\TestCase::tearDown()} so process-reused state in the
     * Pest suite does not bleed across tests.
     */
    public static function resetPinningTracker(): void
    {
        self::$pinnedByModule = false;
    }
}
