<?php

declare(strict_types=1);

use App\Core\Health\UploadLimitChecker;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

Route::get('/', static fn (): JsonResponse => response()->json([
    'name' => 'Catalyst Engine API',
    'status' => 'ok',
]));

Route::get('/health', static function (UploadLimitChecker $uploads): JsonResponse {
    // The process is alive (HTTP 200) — this is a liveness signal, so the
    // status code stays 200 even when a readiness check is degraded; we do
    // NOT want a config issue to trigger a liveness-probe restart loop.
    // Readiness/config problems surface in the body `status` + `checks` so
    // status-aware monitors can alert, and `uploads:check-limits` is the
    // hard deploy gate.
    $uploadsOk = $uploads->isSatisfied();

    return response()->json([
        'status' => $uploadsOk ? 'ok' : 'degraded',
        'service' => 'catalyst-api',
        'timestamp' => now()->toIso8601String(),
        'checks' => [
            'uploads' => [
                'status' => $uploadsOk ? 'ok' : 'degraded',
                'required_bytes' => $uploads->requiredBytes(),
                'effective_ceiling_bytes' => $uploads->effectiveCeilingBytes(),
                'detail' => $uploadsOk
                    ? null
                    : 'PHP upload limits (upload_max_filesize / post_max_size) are below the '
                        .'application maximum; uploads larger than the runtime ceiling will be '
                        .'silently rejected. Raise the limits in the PHP/proxy config.',
            ],
        ],
    ]);
});
