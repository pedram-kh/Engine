<?php

declare(strict_types=1);

use App\TestHelpers\Http\Controllers\SetQueueModeController;
use App\TestHelpers\Http\Middleware\VerifyTestHelperToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Sprint 3 Chunk 3 sub-step 4 — queue-mode override for E2E saga
 * specs. Asserts:
 *   - POST /_test/queue-mode with a valid mode stores it + returns it
 *   - DELETE /_test/queue-mode clears the override
 *   - Invalid modes (not in the allowlist) are rejected with 422
 *   - The endpoint is gated by VerifyTestHelperToken (404 without)
 *   - The middleware applies the cached mode to `config('queue.default')`
 *     on subsequent requests (the canonical use-case)
 */
uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Cache::store('file')->forget(SetQueueModeController::CACHE_KEY);
});

afterEach(function (): void {
    Cache::store('file')->forget(SetQueueModeController::CACHE_KEY);
});

it('POST /_test/queue-mode with mode=sync stores the override and returns it', function (): void {
    $response = $this->withHeader(VerifyTestHelperToken::HEADER, (string) config('test_helpers.token'))
        ->postJson('/api/v1/_test/queue-mode', ['mode' => 'sync']);

    $response->assertOk()
        ->assertJsonPath('data.mode', 'sync');

    expect(Cache::store('file')->get(SetQueueModeController::CACHE_KEY))->toBe('sync');
});

it('POST /_test/queue-mode with mode=database stores the override', function (): void {
    $response = $this->withHeader(VerifyTestHelperToken::HEADER, (string) config('test_helpers.token'))
        ->postJson('/api/v1/_test/queue-mode', ['mode' => 'database']);

    $response->assertOk()
        ->assertJsonPath('data.mode', 'database');

    expect(Cache::store('file')->get(SetQueueModeController::CACHE_KEY))->toBe('database');
});

it('POST /_test/queue-mode rejects modes not in the allowlist with 422', function (): void {
    $response = $this->withHeader(VerifyTestHelperToken::HEADER, (string) config('test_helpers.token'))
        ->postJson('/api/v1/_test/queue-mode', ['mode' => 'totally-fake-driver']);

    $response->assertStatus(422);
    expect(Cache::store('file')->get(SetQueueModeController::CACHE_KEY))->toBeNull();
});

it('POST /_test/queue-mode rejects missing mode with 422', function (): void {
    $response = $this->withHeader(VerifyTestHelperToken::HEADER, (string) config('test_helpers.token'))
        ->postJson('/api/v1/_test/queue-mode', []);

    $response->assertStatus(422);
});

it('DELETE /_test/queue-mode clears the override', function (): void {
    Cache::store('file')->put(SetQueueModeController::CACHE_KEY, 'sync', now()->addHour());
    expect(Cache::store('file')->get(SetQueueModeController::CACHE_KEY))->toBe('sync');

    $response = $this->withHeader(VerifyTestHelperToken::HEADER, (string) config('test_helpers.token'))
        ->deleteJson('/api/v1/_test/queue-mode');

    $response->assertStatus(204);
    expect(Cache::store('file')->get(SetQueueModeController::CACHE_KEY))->toBeNull();
});

it('POST /_test/queue-mode returns 404 without the test helper token', function (): void {
    $response = $this->postJson('/api/v1/_test/queue-mode', ['mode' => 'sync']);

    $response->assertStatus(404);
    expect(Cache::store('file')->get(SetQueueModeController::CACHE_KEY))->toBeNull();
});

it('ApplyTestQueueModeMiddleware overrides config(queue.default) on subsequent api requests', function (): void {
    Cache::store('file')->put(SetQueueModeController::CACHE_KEY, 'sync', now()->addHour());

    // Hit any api route — the middleware ran by the time the controller
    // executes, so the config override should be visible to the route.
    // We use /api/v1/ping for a known minimal route.
    $response = $this->getJson('/api/v1/ping');

    $response->assertOk();
    // After the request, the config has been mutated for that request.
    // The override persists in the test process's container because
    // Laravel doesn't reset config between requests in the same Pest
    // process — sufficient to assert the middleware ran.
    expect(config('queue.default'))->toBe('sync');
});

it('ApplyTestQueueModeMiddleware skips the override when the gate is closed', function (): void {
    Cache::store('file')->put(SetQueueModeController::CACHE_KEY, 'sync', now()->addHour());
    $original = config('queue.default');
    config()->set('test_helpers.token', '');

    $this->getJson('/api/v1/ping');

    expect(config('queue.default'))->toBe($original);
});
