<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Http\Controllers;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Support\DashboardActivityFeed;
use App\Modules\Audit\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/agencies/{agency}/dashboard/activity — Sprint 4 Chunk 1 (1c).
 *
 * The workspace-home activity feed: agency-stamped `audit_logs` rows whose
 * action is in the curated `DashboardActivityFeed::ACTION_ALLOWLIST`,
 * newest-first, capped at `FEED_LIMIT` (15). See `DashboardActivityFeed` for
 * the allowlist + curation rationale (D-c1-8).
 *
 * Authorization + tenancy: same as the summary endpoint — the
 * `auth:web → tenancy.agency → tenancy` group enforces membership. Each row
 * is filtered to `agency_id = {agency}`, so the feed NEVER returns
 * `agency_id`-null (tenant-less) rows and NEVER another agency's rows.
 *
 * PII safety: each row exposes only render-needed fields — `action`, an
 * `actor_label` (the actor's name, eager-loaded to avoid N+1), `created_at`,
 * and a per-action WHITELISTED metadata subset (never the raw blob, never
 * before/after). See `DashboardActivityFeed::safeMetadata`.
 */
final class DashboardActivityController
{
    public function __invoke(Request $request, Agency $agency): JsonResponse
    {
        $rows = AuditLog::query()
            ->where('agency_id', $agency->id)
            ->whereIn('action', DashboardActivityFeed::ACTION_ALLOWLIST)
            // Eager-load the actor's name so per-row `actor_label` resolution
            // is a single extra query, never N+1.
            ->with('actor:id,name')
            ->orderByDesc('created_at')
            // Deterministic tiebreak for rows sharing a `created_at` (the
            // append-only log can write several rows in the same second).
            ->orderByDesc('id')
            ->limit(DashboardActivityFeed::FEED_LIMIT)
            ->get();

        return response()->json([
            'data' => $rows->map(static fn (AuditLog $row): array => [
                'id' => $row->ulid,
                'action' => $row->action->value,
                'actor_label' => $row->actor?->name,
                'created_at' => $row->created_at->toISOString(),
                'metadata' => DashboardActivityFeed::safeMetadata(
                    $row->action->value,
                    $row->metadata,
                ),
            ])->all(),
        ]);
    }
}
