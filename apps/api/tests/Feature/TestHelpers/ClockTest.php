<?php

declare(strict_types=1);

use App\TestHelpers\Http\Middleware\ApplyTestClock;
use App\TestHelpers\Http\Middleware\VerifyTestHelperToken;
use App\TestHelpers\Services\TestClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withHeader(VerifyTestHelperToken::HEADER, (string) config('test_helpers.token'));
});

afterEach(function (): void {
    Carbon::setTestNow();
    Cache::forget((string) config('test_helpers.clock_cache_key'));
});

// -----------------------------------------------------------------------------
// /api/v1/_test/clock — set
// -----------------------------------------------------------------------------

it('pins Carbon::now() to the requested instant for the response', function (): void {
    $this->postJson('/api/v1/_test/clock', ['at' => '2026-05-09T00:00:00Z'])
        ->assertOk()
        ->assertJsonPath('data.at', Carbon::parse('2026-05-09T00:00:00Z')->toIso8601String());

    expect(Carbon::now()->toIso8601String())->toBe(
        Carbon::parse('2026-05-09T00:00:00Z')->toIso8601String(),
    );
});

it('persists the clock to the cache so subsequent requests inherit it', function (): void {
    $this->postJson('/api/v1/_test/clock', ['at' => '2026-12-31T23:59:00Z'])->assertOk();

    /** @var TestClock $clock */
    $clock = app(TestClock::class);
    $current = $clock->current();

    expect($current)->not->toBeNull();
    /** @var Carbon $current */
    expect($current->toIso8601String())->toBe(
        Carbon::parse('2026-12-31T23:59:00Z')->toIso8601String(),
    );
});

it('returns 422 when the at field is missing', function (): void {
    $this->postJson('/api/v1/_test/clock', [])->assertStatus(422);
});

it('returns 422 when the at field is not a parseable timestamp', function (): void {
    $this->postJson('/api/v1/_test/clock', ['at' => 'not-a-real-date-at-all'])
        ->assertStatus(422);
});

// -----------------------------------------------------------------------------
// /api/v1/_test/clock/reset
// -----------------------------------------------------------------------------

it('clears the cached clock and resets Carbon::now() to real time', function (): void {
    $this->postJson('/api/v1/_test/clock', ['at' => '2026-05-09T00:00:00Z'])->assertOk();

    /** @var TestClock $clock */
    $clock = app(TestClock::class);
    expect($clock->current())->not->toBeNull();

    $this->postJson('/api/v1/_test/clock/reset')
        ->assertOk()
        ->assertJsonPath('data.reset', true);

    expect($clock->current())->toBeNull();
});

// -----------------------------------------------------------------------------
// ApplyTestClock middleware: an unrelated request inherits the clock.
// -----------------------------------------------------------------------------

it('replays the cached clock on any subsequent request via ApplyTestClock', function (): void {
    $this->postJson('/api/v1/_test/clock', ['at' => '2030-01-15T12:00:00Z'])->assertOk();

    // We can't assert Carbon inside another endpoint easily without
    // wiring a probe route, so we hit the cache directly to verify
    // the persistence and use a unit-style assertion on the
    // middleware separately. The Playwright spec covers the
    // end-to-end "next request sees Carbon::now()" property.
    /** @var TestClock $clock */
    $clock = app(TestClock::class);
    /** @var Carbon $at */
    $at = $clock->current();

    expect($at->toIso8601String())->toBe(Carbon::parse('2030-01-15T12:00:00Z')->toIso8601String());
});

// -----------------------------------------------------------------------------
// Reset must clear Carbon::setTestNow even in process-reused contexts.
//
// Regression for the leak-across-requests bug: with a previous version of
// ApplyTestClock that only called Carbon::setTestNow when the cache had
// a Carbon value, a fast-forward followed by /_test/clock/reset would
// silently leave Carbon::now() pinned at the fake instant in any context
// that reuses the PHP process (php artisan serve, Octane, the long-lived
// API container the Playwright runner targets). We simulate that by
// driving the middleware twice in the same Pest process and asserting
// the second pass observes real wall-clock time.
// -----------------------------------------------------------------------------

it('clears the pinned Carbon clock on the next gate-open request after a reset', function (): void {
    /** @var TestClock $cacheClock */
    $cacheClock = app(TestClock::class);
    $middleware = app(ApplyTestClock::class);
    $passThrough = static fn (): Response => new Response;

    // Tracker may be true from a prior in-process test that exercised
    // the middleware. Pest's tearDown resets it between tests, but
    // belt-and-suspenders: this regression cares specifically about
    // the set->reset transition, so start from a known-clean tracker.
    ApplyTestClock::resetPinningTracker();

    $cacheClock->set(Carbon::parse('1990-01-01T00:00:00Z'));

    // Request N: the middleware reads the far-past cached clock and
    // pins Carbon::now() to it. The pinning tracker flips to true.
    $middleware->handle(Request::create('/health', 'GET'), $passThrough);
    expect(Carbon::now()->year)->toBe(1990);

    $cacheClock->reset();

    // Request N+1: cache is empty AND the tracker is true (we pinned
    // on the prior request). With the leak bug — a conditional call
    // that only fired when the cache had a Carbon value — Carbon
    // would still report 1990. The corrected middleware must observe
    // the tracker, call Carbon::setTestNow() with no argument, and
    // release the pin.
    $middleware->handle(Request::create('/health', 'GET'), $passThrough);

    expect(abs(Carbon::now()->getTimestamp() - time()))->toBeLessThanOrEqual(5,
        'Reset must release Carbon::setTestNow within ~5 seconds of real wall-clock time.',
    );
});

// -----------------------------------------------------------------------------
// Defensive: corrupt cache value does not crash subsequent requests.
// -----------------------------------------------------------------------------

it('returns null from TestClock::current() when the cache value is corrupted', function (): void {
    Cache::forever((string) config('test_helpers.clock_cache_key'), 'not-a-real-iso-timestamp');

    /** @var TestClock $clock */
    $clock = app(TestClock::class);

    expect($clock->current())->toBeNull();
});
