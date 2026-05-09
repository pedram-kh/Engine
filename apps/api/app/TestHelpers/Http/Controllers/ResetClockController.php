<?php

declare(strict_types=1);

namespace App\TestHelpers\Http\Controllers;

use App\TestHelpers\Services\TestClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * POST /api/v1/_test/clock/reset
 *
 * Clears the cached test clock. After this call, every subsequent
 * request reverts to Carbon::now() driven by real time — both the
 * cache key is removed (so {@see App\TestHelpers\Http\Middleware\ApplyTestClock}
 * stops re-arming) and Carbon::setTestNow() is reset on the current
 * process for in-flight cleanup.
 *
 * The Playwright spec calls this at the end of every test that
 * touched the clock (typically in an afterEach) so a stray clock
 * from a prior failure can never bleed into the next test.
 */
final class ResetClockController
{
    public function __invoke(TestClock $clock): JsonResponse
    {
        $clock->reset();
        Carbon::setTestNow();

        return new JsonResponse([
            'data' => ['reset' => true],
        ]);
    }
}
