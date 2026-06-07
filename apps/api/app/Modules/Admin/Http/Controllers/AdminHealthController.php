<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Identity\Enums\UserType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Admin system-health probe (Sprint 13, D-8).
 *
 *   GET /api/v1/admin/health
 *
 * A small, cheap liveness probe over the core dependencies (DB + cache),
 * surfaced on the Operations → System health page. Queue depth / failed
 * jobs are served by the gated Horizon embed; this endpoint answers "are
 * the backing services reachable". Each probe is isolated — one failing
 * dependency reports `error` without taking the others (or the request)
 * down — and the top-level `status` is `ok` only when every probe passes.
 *
 * Cross-agency / tenant-less BY DESIGN — platform infrastructure, not an
 * agency resource. platform_admin-gated (the bounded bypass).
 */
final class AdminHealthController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin($request);

        $checks = [
            'database' => $this->probe(static function (): void {
                DB::connection()->getPdo();
            }),
            'cache' => $this->probe(static function (): void {
                Cache::store()->put('admin:health:ping', '1', 5);
                Cache::store()->get('admin:health:ping');
            }),
        ];

        $healthy = ! in_array('error', array_values($checks), true);

        return response()->json([
            'data' => [
                'status' => $healthy ? 'ok' : 'degraded',
                'checks' => $checks,
            ],
        ]);
    }

    private function probe(callable $check): string
    {
        try {
            $check();

            return 'ok';
        } catch (Throwable) {
            return 'error';
        }
    }

    private function authorizePlatformAdmin(Request $request): void
    {
        $user = $request->user();
        if ($user === null) {
            abort(Response::HTTP_UNAUTHORIZED);
        }
        if ($user->type !== UserType::PlatformAdmin) {
            abort(Response::HTTP_FORBIDDEN);
        }
    }
}
