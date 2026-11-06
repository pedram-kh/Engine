<?php

declare(strict_types=1);

use App\Modules\Notifications\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

require_once dirname(__DIR__, 3).'/Support/MessageRevisionFixtures.php';

/*
 * AH-119 B2 — edit and delete are silent: no in-app notification, no push, no mail, no queued job.
 * The positive control proves the probes can see a notification when one is meant to exist.
 */

it('control: sending a message through the API does notify, so the probes below are able to see', function (): void {
    $w = revWorld();
    $queue = Queue::fake();

    $this->actingAs($w['admin'])->postJson("/api/v1/agencies/{$w['agency']->ulid}/creators/{$w['creator']->ulid}/relationship-messages", ['body' => 'hello'])->assertCreated();

    expect(Notification::query()->count() + count($queue->pushedJobs()))->toBeGreaterThan(0);
});

it('edit and delete create no notification, queue no job and send no mail', function (string $surface): void {
    $w = revWorld();
    $s = revSurface($w, $surface, 'quiet');
    $queue = Queue::fake();
    Mail::fake();
    $before = Notification::query()->count();

    $this->actingAs($s['actor'])->patchJson($s['url'], ['body' => 'quieter'])->assertOk();
    $this->actingAs($s['actor'])->deleteJson($s['url'])->assertOk();

    expect(Notification::query()->count())->toBe($before)
        ->and($queue->pushedJobs())->toBe([]);
    Mail::assertNothingSent();
    Mail::assertNothingQueued();
})->with(revSurfaceDataset());
