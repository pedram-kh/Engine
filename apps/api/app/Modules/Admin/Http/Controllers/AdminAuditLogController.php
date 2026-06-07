<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Enums\UserType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * Admin audit-log viewer (Sprint 13, D-5).
 *
 *   GET /api/v1/admin/audit-logs
 *
 * Read-only, cross-agency view over the append-only `audit_logs` table.
 * Every filter targets an INDEXED column (the volume concern — the table
 * grows unbounded):
 *   - action      → idx_audit_action
 *   - actor_id    → idx_audit_actor (with actor_type=user)
 *   - subject_ulid / subject_type+subject_id → idx_audit_subject
 *   - agency_id   → idx_audit_agency_created
 *   - date_from / date_to (created_at) → idx_audit_created_at
 *
 * CURSOR pagination (not offset): at audit volume, `LIMIT/OFFSET` degrades
 * linearly, so we keyset-paginate on the monotonic `id`. The response
 * carries opaque `next_cursor` / `prev_cursor` tokens.
 *
 * Cross-agency BY DESIGN — the platform_admin bounded bypass
 * (docs/security/tenancy.md § 4). AuditLog is not tenant-scoped (no
 * BelongsToAgency global scope), so the read is naturally cross-agency;
 * the explicit platform_admin gate is the bound.
 */
final class AdminAuditLogController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin($request);

        $perPage = max(1, min((int) $request->integer('per_page', 50), 100));

        $query = AuditLog::query()
            ->with('actor:id,name,email')
            ->orderByDesc('id');

        $action = $request->query('action');
        if (is_string($action) && $action !== '' && AuditAction::tryFrom($action) !== null) {
            $query->where('action', $action);
        }

        $actorId = $request->query('actor_id');
        if (is_numeric($actorId)) {
            $query->where('actor_type', 'user')->where('actor_id', (int) $actorId);
        }

        $agencyId = $request->query('agency_id');
        if (is_numeric($agencyId)) {
            $query->where('agency_id', (int) $agencyId);
        }

        $subjectUlid = $request->query('subject_ulid');
        if (is_string($subjectUlid) && $subjectUlid !== '') {
            $query->where('subject_ulid', $subjectUlid);
        }

        $dateFrom = $this->parseDate($request->query('date_from'));
        if ($dateFrom !== null) {
            $query->where('created_at', '>=', $dateFrom->startOfDay());
        }

        $dateTo = $this->parseDate($request->query('date_to'));
        if ($dateTo !== null) {
            $query->where('created_at', '<=', $dateTo->endOfDay());
        }

        $paginator = $query->cursorPaginate($perPage)->withQueryString();

        $data = array_map(
            static fn (AuditLog $log): array => self::serialize($log),
            $paginator->items(),
        );

        return response()->json([
            'data' => $data,
            'meta' => [
                'per_page' => $paginator->perPage(),
                'next_cursor' => $paginator->nextCursor()?->encode(),
                'prev_cursor' => $paginator->previousCursor()?->encode(),
                'has_more' => $paginator->hasMorePages(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function serialize(AuditLog $log): array
    {
        return [
            'id' => $log->ulid,
            'type' => 'audit_logs',
            'attributes' => [
                'action' => $log->action->value,
                'actor_id' => $log->actor_id,
                'actor_name' => $log->actor?->name,
                'actor_email' => $log->actor?->email,
                'actor_role' => $log->actor_role,
                'agency_id' => $log->agency_id,
                'subject_type' => $log->subject_type,
                'subject_ulid' => $log->subject_ulid,
                'reason' => $log->reason,
                'ip' => $log->ip,
                'created_at' => $log->created_at->toIso8601String(),
            ],
        ];
    }

    private function parseDate(mixed $raw): ?Carbon
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
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
