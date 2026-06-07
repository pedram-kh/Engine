<?php

declare(strict_types=1);

namespace App\Modules\Creators\Http\Controllers\Admin;

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Creators\Models\Creator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Admin creator-detail history reads (Sprint 13, D-4).
 *
 *   GET /api/v1/admin/creators/{creator}/assignments — read-only
 *        campaign/assignment history across ALL agencies.
 *   GET /api/v1/admin/creators/{creator}/audit-logs  — per-creator audit
 *        trail (the AuditLog rows whose subject IS this creator).
 *
 * Cross-agency BY DESIGN (docs/security/tenancy.md § 4 — the platform_admin
 * bounded bypass). CampaignAssignment is tenant-scoped via
 * {@see BelongsToAgencyScope}; the admin read strips that scope explicitly
 * (the documented cross-tenant pattern) because a creator's campaign
 * history spans every agency that ever engaged them. Both surfaces are
 * READ-ONLY — no state mutation, no audit row of their own.
 *
 * The payment columns on CampaignAssignment (agreed_fee_*, payment_id, …)
 * are deliberately NOT serialized here: the creator-detail payment section
 * is a discrete coming-soon block (D-13) lit up in S10, so this history
 * read stays payment-free and the payment block swaps in independently.
 */
final class AdminCreatorHistoryController
{
    /**
     * GET /api/v1/admin/creators/{creator}/assignments.
     */
    public function assignments(Request $request, Creator $creator): JsonResponse
    {
        $this->authorize($request, 'view', $creator);

        $perPage = max(1, min((int) $request->integer('per_page', 25), 100));

        $paginator = CampaignAssignment::query()
            ->withoutGlobalScope(BelongsToAgencyScope::class)
            ->where('creator_id', $creator->id)
            ->with([
                'campaign:id,ulid,name',
                'brand:id,ulid,name',
                'agency:id,ulid,name',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        /** @var list<CampaignAssignment> $assignments */
        $assignments = $paginator->items();

        $data = array_map(static fn (CampaignAssignment $a): array => [
            'id' => $a->ulid,
            'type' => 'campaign_assignments',
            'attributes' => [
                'status' => $a->status->value,
                'campaign_name' => $a->campaign?->name,
                'brand_name' => $a->brand?->name,
                'agency_name' => $a->agency->name,
                'invited_at' => $a->invited_at?->toIso8601String(),
                'accepted_at' => $a->accepted_at?->toIso8601String(),
                'posted_at' => $a->posted_at?->toIso8601String(),
                'created_at' => $a->created_at->toIso8601String(),
            ],
        ], $assignments);

        return response()->json([
            'data' => $data,
            'meta' => [
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/v1/admin/creators/{creator}/audit-logs.
     *
     * The audit trail whose SUBJECT is this creator (approve / reject /
     * field edits / KYC clearance). Reads `audit_logs` filtered by the
     * morph subject; the table is append-only so this is a pure read.
     */
    public function auditLogs(Request $request, Creator $creator): JsonResponse
    {
        $this->authorize($request, 'view', $creator);

        $perPage = max(1, min((int) $request->integer('per_page', 25), 100));

        $paginator = AuditLog::query()
            ->where('subject_type', $creator->getMorphClass())
            ->where('subject_id', $creator->id)
            ->with('actor:id,name,email')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        /** @var list<AuditLog> $logs */
        $logs = $paginator->items();

        $data = array_map(static fn (AuditLog $log): array => [
            'id' => $log->ulid,
            'type' => 'audit_logs',
            'attributes' => [
                'action' => $log->action->value,
                'actor_name' => $log->actor?->name,
                'actor_email' => $log->actor?->email,
                'reason' => $log->reason,
                'created_at' => $log->created_at->toIso8601String(),
            ],
        ], $logs);

        return response()->json([
            'data' => $data,
            'meta' => [
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    private function authorize(Request $request, string $ability, Creator $creator): void
    {
        $user = $request->user();
        if ($user === null) {
            abort(Response::HTTP_UNAUTHORIZED);
        }
        if (! $user->can($ability, $creator)) {
            abort(Response::HTTP_FORBIDDEN);
        }
    }
}
