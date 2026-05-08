<?php

declare(strict_types=1);

use App\Modules\Identity\Services\FailedLoginTracker;
use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(TestCase::class);

afterEach(function (): void {
    Carbon::setTestNow();
});

it('starts with zero counts and no lock recommendation', function (): void {
    $tracker = app(FailedLoginTracker::class);
    expect($tracker->shortWindowCount('a@example.com'))->toBe(0)
        ->and($tracker->longWindowCount('a@example.com'))->toBe(0)
        ->and($tracker->shouldTemporarilyLock('a@example.com'))->toBeFalse()
        ->and($tracker->shouldEscalate('a@example.com'))->toBeFalse();
});

it('returns the in-window counts on each record() call', function (): void {
    Carbon::setTestNow('2026-05-08T00:00:00Z');

    $tracker = app(FailedLoginTracker::class);
    $r1 = $tracker->record('user@example.com');
    $r2 = $tracker->record('user@example.com');

    expect($r1)->toBe(['short_window_count' => 1, 'long_window_count' => 1])
        ->and($r2)->toBe(['short_window_count' => 2, 'long_window_count' => 2]);
});

it('crosses the short-window threshold on the 5th attempt', function (): void {
    Carbon::setTestNow('2026-05-08T00:00:00Z');

    $tracker = app(FailedLoginTracker::class);

    for ($i = 1; $i <= 4; $i++) {
        $tracker->record('user@example.com');
        expect($tracker->shouldTemporarilyLock('user@example.com'))->toBeFalse(
            "should not be locked after {$i} attempts",
        );
    }

    $tracker->record('user@example.com');

    expect($tracker->shouldTemporarilyLock('user@example.com'))->toBeTrue()
        ->and($tracker->shortWindowCount('user@example.com'))->toBe(5);
});

it('crosses the long-window threshold on the 10th attempt across hours', function (): void {
    Carbon::setTestNow('2026-05-08T00:00:00Z');
    $tracker = app(FailedLoginTracker::class);

    for ($i = 1; $i <= 9; $i++) {
        $tracker->record('user@example.com');
        Carbon::setTestNow(Carbon::now()->addMinutes(60));
    }

    expect($tracker->shouldEscalate('user@example.com'))->toBeFalse();

    $tracker->record('user@example.com');

    expect($tracker->shouldEscalate('user@example.com'))->toBeTrue()
        ->and($tracker->longWindowCount('user@example.com'))->toBe(10);
});

it('drops attempts older than 24 hours from the long-window count', function (): void {
    Carbon::setTestNow('2026-05-08T00:00:00Z');
    $tracker = app(FailedLoginTracker::class);

    for ($i = 1; $i <= 5; $i++) {
        $tracker->record('user@example.com');
    }

    Carbon::setTestNow('2026-05-09T00:01:00Z');

    expect($tracker->longWindowCount('user@example.com'))->toBe(0)
        ->and($tracker->shouldEscalate('user@example.com'))->toBeFalse();
});

it('drops attempts older than 15 minutes from the short-window count', function (): void {
    Carbon::setTestNow('2026-05-08T00:00:00Z');
    $tracker = app(FailedLoginTracker::class);

    for ($i = 1; $i <= 5; $i++) {
        $tracker->record('user@example.com');
    }

    Carbon::setTestNow('2026-05-08T00:16:00Z');

    expect($tracker->shortWindowCount('user@example.com'))->toBe(0)
        ->and($tracker->shouldTemporarilyLock('user@example.com'))->toBeFalse()
        ->and($tracker->longWindowCount('user@example.com'))->toBe(5);
});

it('clear() drops all in-window state for an email', function (): void {
    $tracker = app(FailedLoginTracker::class);
    for ($i = 1; $i <= 4; $i++) {
        $tracker->record('user@example.com');
    }
    $tracker->clear('user@example.com');

    expect($tracker->shortWindowCount('user@example.com'))->toBe(0)
        ->and($tracker->longWindowCount('user@example.com'))->toBe(0);
});

it('normalises emails (case + whitespace) so attackers cannot dodge the bucket', function (): void {
    $tracker = app(FailedLoginTracker::class);
    $tracker->record(' User@Example.COM ');
    $tracker->record('user@example.com');

    expect($tracker->shortWindowCount('user@example.com'))->toBe(2)
        ->and($tracker->shortWindowCount('USER@example.com'))->toBe(2);
});
