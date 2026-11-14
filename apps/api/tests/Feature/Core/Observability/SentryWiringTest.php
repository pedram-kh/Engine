<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Support\SentryRecording;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Sentry wiring (AH-121 C1): handles() and the user hook
|--------------------------------------------------------------------------
|
| Requests go through the real HTTP kernel and the app's real exception
| handler; the only fake is the HTTP client behind the SDK transport, which
| RECORDS. No real DSN.
*/

final class SentryBoomJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
        throw new RuntimeException('job exploded');
    }
}

beforeEach(function (): void {
    Route::get('/api/v1/_sentry-test/boom', fn () => throw new RuntimeException('route exploded'));
    Route::get('/api/v1/_sentry-test/invalid', fn () => request()->validate(['field' => 'required']));
    Route::get('/api/v1/_sentry-test/not-found', fn () => abort(404));
    Route::get('/api/v1/_sentry-test/job', function () {
        SentryBoomJob::dispatch();

        return response()->noContent();
    });
});

afterEach(function (): void {
    SentryRecording::reset();
});

it('an exception thrown in a route reaches the recording transport exactly once', function (): void {
    $sentry = SentryRecording::install();

    $this->getJson('/api/v1/_sentry-test/boom')->assertStatus(500);
    $sentry->flush();

    expect($sentry->bodies)->toHaveCount(1);
    expect($sentry->event()['exception'][0]['type'])->toBe('RuntimeException');
});

it('a signed-in creator gives {id: ulid} and user_type creator', function (): void {
    $sentry = SentryRecording::install();
    $creator = User::factory()->creator()->createOne();

    $this->actingAs($creator, 'web')->getJson('/api/v1/_sentry-test/boom')->assertStatus(500);
    $sentry->flush();

    expect($sentry->bodies)->toHaveCount(1);
    expect($sentry->event()['user'])->toBe(['id' => $creator->ulid]);
    expect($sentry->event()['tags']['user_type'])->toBe('creator');
});

it('tags an agency user and a platform admin', function (): void {
    $sentry = SentryRecording::install();
    $agencyUser = User::factory()->agencyAdmin()->createOne();
    $admin = User::factory()->platformAdmin()->createOne();

    $this->actingAs($agencyUser, 'web')->getJson('/api/v1/_sentry-test/boom');
    $this->actingAs($admin, 'web_admin')->getJson('/api/v1/_sentry-test/boom');
    $sentry->flush();

    expect($sentry->bodies)->toHaveCount(2);
    expect($sentry->event(0)['user'])->toBe(['id' => $agencyUser->ulid]);
    expect($sentry->event(0)['tags']['user_type'])->toBe('agency');
    expect($sentry->event(1)['user'])->toBe(['id' => $admin->ulid]);
    expect($sentry->event(1)['tags']['user_type'])->toBe('admin');
});

it('a creator on the token guard gives the ulid and creator', function (): void {
    $sentry = SentryRecording::install();
    $creator = User::factory()->creator()->createOne();

    $this->actingAs($creator, 'creator_app')->getJson('/api/v1/_sentry-test/boom')->assertStatus(500);
    $sentry->flush();

    expect($sentry->event()['user'])->toBe(['id' => $creator->ulid]);
    expect($sentry->event()['tags']['user_type'])->toBe('creator');
});

it('a guest has no user block', function (): void {
    $sentry = SentryRecording::install();

    $this->getJson('/api/v1/_sentry-test/boom')->assertStatus(500);
    $sentry->flush();

    expect($sentry->bodies)->toHaveCount(1);
    expect($sentry->event()['user'])->toBeNull();
    expect($sentry->event()['tags'])->not->toHaveKey('user_type');
});

it('a request after a signed-in one, once signed out, has no user block', function (): void {
    $sentry = SentryRecording::install();
    $creator = User::factory()->creator()->createOne();

    $this->actingAs($creator, 'web')->getJson('/api/v1/_sentry-test/boom');
    $this->postJson('/api/v1/auth/logout')->assertNoContent();
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/_sentry-test/boom');
    $sentry->flush();

    expect($sentry->bodies)->toHaveCount(2);
    expect($sentry->event(0)['user'])->toBe(['id' => $creator->ulid]);
    expect($sentry->event(1)['user'])->toBeNull();
});

it('a 422 and a 404 send nothing', function (): void {
    $sentry = SentryRecording::install();

    $this->getJson('/api/v1/_sentry-test/invalid')->assertStatus(422);
    $this->getJson('/api/v1/_sentry-test/not-found')->assertStatus(404);
    $this->getJson('/api/v1/_sentry-test/no-such-route')->assertStatus(404);
    $sentry->flush();

    expect($sentry->bodies)->toBe([]);
});

it('an exception inside a queued job sends one event with no user, even for a signed-in request', function (): void {
    $sentry = SentryRecording::install();
    $creator = User::factory()->creator()->createOne();

    // QUEUE_CONNECTION=sync in the test env: the job runs inside the request, the
    // creator is still in memory, and the failure propagates to the handler.
    $this->actingAs($creator, 'web')->getJson('/api/v1/_sentry-test/job')->assertStatus(500);
    $sentry->flush();

    expect($sentry->bodies)->toHaveCount(1);
    expect($sentry->event()['exception'][0]['type'])->toBe('RuntimeException');
    expect($sentry->event()['user'])->toBeNull();
    expect($sentry->event()['tags'])->not->toHaveKey('user_type');
});

it('an exception inside a job run by a real queue worker sends one event with no user', function (): void {
    config()->set('queue.default', 'database');
    $sentry = SentryRecording::install();
    $creator = User::factory()->creator()->createOne();

    SentryBoomJob::dispatch();

    // The creator is signed in, in memory, when the worker process runs.
    $this->actingAs($creator, 'web');
    $this->artisan('queue:work', ['--once' => true, '--tries' => 1, '--stop-when-empty' => true, '--quiet' => true]);
    $sentry->flush();

    expect($sentry->bodies)->toHaveCount(1);
    expect($sentry->event()['user'])->toBeNull();
});
