<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

require_once dirname(__DIR__, 3).'/Support/MessageRevisionFixtures.php';

/*
 * AH-119 B2 — the 15-minute window runs from `created_at` on the SERVER clock, and an edit never extends it.
 * Carbon::setTestNow drives the clock. 14:59 is allowed, 15:00 is the last allowed instant, 15:01 is refused.
 */

afterEach(fn () => Carbon::setTestNow());

/** @return array{0: array<string, mixed>, 1: array{url: string, actor: User, message: mixed, type: string, table: string}} */
function windowFixture(string $surface): array
{
    $w = revWorld();
    Carbon::setTestNow(Carbon::parse('2026-11-06 10:00:00'));

    return [$w, revSurface($w, $surface)];
}

it('allows an edit 14:59 after sending', function (string $surface): void {
    [, $s] = windowFixture($surface);
    Carbon::setTestNow(Carbon::parse('2026-11-06 10:14:59'));

    $this->actingAs($s['actor'])->patchJson($s['url'], ['body' => 'in time'])->assertOk();
})->with(revSurfaceDataset());

it('allows an edit at exactly 15:00 and refuses one at 15:01: message.edit_window_closed', function (string $surface): void {
    [, $s] = windowFixture($surface);

    Carbon::setTestNow(Carbon::parse('2026-11-06 10:15:00'));
    $this->actingAs($s['actor'])->patchJson($s['url'], ['body' => 'on the line'])->assertOk();

    Carbon::setTestNow(Carbon::parse('2026-11-06 10:15:01'));
    $this->actingAs($s['actor'])->patchJson($s['url'], ['body' => 'too late'])
        ->assertUnprocessable()->assertJsonPath('errors.0.code', 'message.edit_window_closed');

    expect(revRow($s['table'], $s['message']->ulid)->body)->toBe('on the line');
})->with(revSurfaceDataset());

it('does not extend the window: an edit at 14:59 leaves 15:01 closed', function (string $surface): void {
    [, $s] = windowFixture($surface);

    Carbon::setTestNow(Carbon::parse('2026-11-06 10:14:59'));
    $this->actingAs($s['actor'])->patchJson($s['url'], ['body' => 'edited near the end'])->assertOk();

    Carbon::setTestNow(Carbon::parse('2026-11-06 10:15:01'));
    $this->actingAs($s['actor'])->patchJson($s['url'], ['body' => 'a second edit'])
        ->assertUnprocessable()->assertJsonPath('errors.0.code', 'message.edit_window_closed');

    expect(revRow($s['table'], $s['message']->ulid)->body)->toBe('edited near the end');
})->with(revSurfaceDataset());

it('measures from created_at, not from updated_at or an earlier edit', function (): void {
    [, $s] = windowFixture('agency-campaign');
    Carbon::setTestNow(Carbon::parse('2026-11-06 10:10:00'));
    $this->actingAs($s['actor'])->patchJson($s['url'], ['body' => 'v2'])->assertOk();

    Carbon::setTestNow(Carbon::parse('2026-11-06 10:16:00')); // 6 minutes after the edit, 16 after sending
    $this->actingAs($s['actor'])->patchJson($s['url'], ['body' => 'v3'])->assertUnprocessable();
});

it('lets the sender delete long after the window', function (): void {
    [, $s] = windowFixture('agency-direct');
    Carbon::setTestNow(Carbon::parse('2026-11-08 10:00:00'));

    $this->actingAs($s['actor'])->deleteJson($s['url'])->assertOk();
});
