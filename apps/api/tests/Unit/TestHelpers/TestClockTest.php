<?php

declare(strict_types=1);

use App\TestHelpers\Services\TestClock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class);

afterEach(function (): void {
    Cache::forget((string) config('test_helpers.clock_cache_key'));
});

it('round-trips an instant via the cache', function (): void {
    /** @var TestClock $clock */
    $clock = app(TestClock::class);

    $clock->set(Carbon::parse('2026-05-09T00:00:00Z'));

    /** @var Carbon $current */
    $current = $clock->current();
    expect($current->toIso8601String())->toBe(
        Carbon::parse('2026-05-09T00:00:00Z')->toIso8601String(),
    );
});

it('returns null when no value is cached', function (): void {
    Cache::forget((string) config('test_helpers.clock_cache_key'));

    /** @var TestClock $clock */
    $clock = app(TestClock::class);

    expect($clock->current())->toBeNull();
});

it('returns null when the cache value is empty', function (): void {
    Cache::forever((string) config('test_helpers.clock_cache_key'), '');

    /** @var TestClock $clock */
    $clock = app(TestClock::class);

    expect($clock->current())->toBeNull();
});

it('returns null when the cache value is unparseable garbage', function (): void {
    Cache::forever((string) config('test_helpers.clock_cache_key'), 'absolutely-not-a-timestamp');

    /** @var TestClock $clock */
    $clock = app(TestClock::class);

    expect($clock->current())->toBeNull();
});

it('reset() removes the cache key', function (): void {
    /** @var TestClock $clock */
    $clock = app(TestClock::class);

    $clock->set(Carbon::parse('2026-12-31T00:00:00Z'));
    expect($clock->current())->not->toBeNull();

    $clock->reset();
    expect($clock->current())->toBeNull();
});
