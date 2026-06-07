<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Creators\Enums\ApplicationStatus;
use App\Modules\Creators\Enums\KycStatus;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Enums\UserType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Admin operational dashboard (Sprint 13, D-7).
 *
 *   GET /api/v1/admin/dashboard/summary  — non-payment operational KPIs
 *   GET /api/v1/admin/dashboard/activity — the recent audit activity feed
 *
 * The summary backs the dashboard KPI strip. The NON-payment cards are real
 * counts (agency totals, the creator-approval backlog, the KYC queue depth,
 * queue health); the payment/dispute cards are STABLE `null` placeholders
 * (D-13 coming-soon — the SPA renders a muted `—`) that light up in place
 * when payment processing ships (Sprint 10). They are kept here as a
 * discrete, swappable contract, not woven into the live counts.
 *
 * Cross-agency BY DESIGN — platform-wide operational visibility is the
 * platform_admin bounded bypass (docs/security/tenancy.md § 4). No agency
 * filter; the explicit platform_admin gate is the bound.
 */
final class AdminDashboardController
{
    private const FEED_LIMIT = 25;

    public function summary(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin($request);

        return response()->json([
            'data' => [
                'agencies_total' => Agency::query()->count(),
                'agencies_active' => Agency::query()->whereNull('suspended_at')->count(),
                'agencies_suspended' => Agency::query()->whereNotNull('suspended_at')->count(),
                'creators_pending_approval' => Creator::query()
                    ->where('application_status', ApplicationStatus::Pending->value)
                    ->count(),
                'creators_pending_kyc' => Creator::query()
                    ->where('kyc_status', KycStatus::Pending->value)
                    ->count(),
                'queue_pending' => DB::table('jobs')->count(),
                'queue_failed' => DB::table('failed_jobs')->count(),
                // Coming-soon (D-13) — stable null placeholders that light up
                // when payment processing ships (Sprint 10). The SPA renders
                // a muted dash; nothing here is a live number.
                'open_disputes' => null,
                'failed_payments_today' => null,
            ],
        ]);
    }

    public function activity(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin($request);

        $rows = AuditLog::query()
            ->with('actor:id,name,email')
            ->orderByDesc('id')
            ->limit(self::FEED_LIMIT)
            ->get();

        $data = $rows
            ->map(static fn (AuditLog $log): array => [
                'id' => $log->ulid,
                'type' => 'audit_logs',
                'attributes' => [
                    'action' => $log->action->value,
                    'actor_name' => $log->actor?->name,
                    'actor_email' => $log->actor?->email,
                    'reason' => $log->reason,
                    'created_at' => $log->created_at->toIso8601String(),
                ],
            ])
            ->all();

        return response()->json(['data' => $data]);
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
