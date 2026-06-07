<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Identity\Enums\UserType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Admin GDPR-compliance queues (Sprint 13, D-11) — SHELLS.
 *
 *   GET /api/v1/admin/compliance/export-requests
 *   GET /api/v1/admin/compliance/erasure-queue
 *
 * These are the data-subject export (GDPR art. 15/20) and erasure (art. 17)
 * operator surfaces. They ship THIS sprint as empty shells: the routes,
 * the SPA pages, and the response envelope all exist now so Sprint 14 —
 * which lands the `data_export_requests` / `data_erasure_requests` tables
 * and the actual export/erasure machinery — fills data into a finished
 * surface rather than building one.
 *
 * The contract is deliberate: each endpoint returns 200 with an empty
 * `data: []` (NOT 404). A 404 would tell the SPA "this feature does not
 * exist"; an empty list tells it "this feature exists and currently has
 * no pending requests" — the truthful state until S14 wires the queues.
 *
 * Cross-agency / tenant-less BY DESIGN — a platform compliance function,
 * not an agency resource. platform_admin-gated (the bounded bypass) +
 * EnsureMfaForAdmins, like every other admin surface. Read-only this
 * sprint; the actioning verbs (approve export, execute erasure) land with
 * their tables in S14.
 */
final class AdminComplianceController
{
    public function exports(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin($request);

        return $this->emptyQueue();
    }

    public function erasures(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin($request);

        return $this->emptyQueue();
    }

    /**
     * The shared shell envelope. `meta.shell` is the honest signal that the
     * backing store is not yet wired (S14) — the SPA reads it to render the
     * "coming in a later sprint" empty-state copy instead of a neutral
     * "no results" message.
     */
    private function emptyQueue(): JsonResponse
    {
        return response()->json([
            'data' => [],
            'meta' => [
                'total' => 0,
                'shell' => true,
            ],
        ]);
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
