<?php

declare(strict_types=1);

namespace App\TestHelpers\Http\Controllers;

use App\TestHelpers\Services\TestClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * POST /api/v1/_test/clock  { "at": "2026-05-09T00:00:00Z" }
 *
 * Pins the application clock to the given ISO 8601 timestamp. Every
 * subsequent request handled by the same API process (or any process
 * sharing the cache backend) will see Carbon::now() return that
 * instant — see {@see App\TestHelpers\Http\Middleware\ApplyTestClock}.
 *
 * The endpoint accepts any value Carbon::parse() understands. Invalid
 * input returns 422 so the spec gets a clear failure rather than
 * silently sliding into real time.
 *
 * The endpoint also calls Carbon::setTestNow() inline so the response
 * to THIS request reports the simulated clock too — handy for
 * specs that want to chain `set-clock + read /me` and assert ordering.
 */
final class SetClockController
{
    public function __invoke(Request $request, TestClock $clock): JsonResponse
    {
        $at = (string) $request->input('at', '');

        if ($at === '') {
            return new JsonResponse([
                'error' => 'at field is required (ISO 8601 timestamp)',
            ], 422);
        }

        try {
            $instant = Carbon::parse($at);
        } catch (\Throwable) {
            return new JsonResponse([
                'error' => 'at must be a valid ISO 8601 timestamp',
            ], 422);
        }

        $clock->set($instant);
        Carbon::setTestNow($instant);

        return new JsonResponse([
            'data' => [
                'at' => $instant->toIso8601String(),
            ],
        ]);
    }
}
