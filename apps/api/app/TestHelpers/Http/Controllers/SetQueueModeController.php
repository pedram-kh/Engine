<?php

declare(strict_types=1);

namespace App\TestHelpers\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Test-helper endpoint for E2E queue mode overrides.
 *
 * Sprint 3 Chunk 3 sub-step 4 — Playwright lacks Pest's
 * `Queue::fake()` primitive, so the SPA-driven happy-path spec
 * needs a way to flip the running app between `sync` (jobs execute
 * inline; status-poll sagas terminate before the SPA sees the
 * response) and `database` (jobs queue; status-poll sagas exercise
 * the polling loop).
 *
 * Gating chain (defence-in-depth #40):
 *   1. {@see TestHelpersServiceProvider::gateOpen()} —
 *      env is local/testing AND TEST_HELPERS_TOKEN is non-empty.
 *   2. {@see VerifyTestHelperToken} middleware — runtime check on
 *      every request, so config flip closes the gate immediately.
 *   3. Input validation: `mode` must be one of the allowlisted
 *      strings; reject anything else with 422.
 *
 * The runtime config override is applied by
 * {@see ApplyTestQueueModeMiddleware} which reads the cache on
 * every request and calls `config()->set('queue.default', $mode)`.
 *
 * Cache store: `file` (process-shared) — sticky across requests, so
 * a Playwright test that sets sync mode in `beforeEach` will see
 * sync mode applied for every subsequent navigation in that test.
 * Each test should call DELETE in `afterEach` to clean up.
 */
final class SetQueueModeController
{
    public const CACHE_KEY = 'catalyst.test.queue_mode';

    /** @var array<int, string> */
    public const ALLOWED_MODES = ['sync', 'database', 'redis'];

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'mode' => ['required', 'string', 'in:'.implode(',', self::ALLOWED_MODES)],
        ]);

        $mode = (string) $request->string('mode');

        // The validation rule above already constrains the value, but
        // we re-check defensively here so the cache write never
        // becomes the gating layer.
        if (! in_array($mode, self::ALLOWED_MODES, true)) {
            return ErrorResponse::single(
                $request,
                422,
                'test_helper.invalid_queue_mode',
                'Queue mode must be one of: '.implode(', ', self::ALLOWED_MODES),
            );
        }

        Cache::store('file')->put(self::CACHE_KEY, $mode, now()->addHour());

        return response()->json(['data' => ['mode' => $mode]]);
    }

    public function destroy(): JsonResponse
    {
        Cache::store('file')->forget(self::CACHE_KEY);

        return response()->json([], 204);
    }
}
