<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Http\Controllers;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Creators\Enums\ApplicationStatus;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Creators\Models\Creator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/agencies/{agency}/dashboard/summary — Sprint 4 Chunk 1 (1b).
 *
 * One agency-scoped summary payload backing the four workspace-home KPI
 * cards (D-c1-6: a single endpoint, not per-KPI). Two KPIs are real now;
 * two are stable `null` placeholders that slot in when campaigns / payments
 * ship (the frontend renders a muted `—` for `null`).
 *
 * Authorization + tenancy: the `auth:web → tenancy.agency → tenancy` route
 * group enforces authentication + agency membership (a non-member gets a
 * 404 invisibility response from `tenancy.agency`, matching the members
 * endpoint). No MFA gate — this matches the `/` route, which is not
 * MFA-gated (see apps/main agency-routes-mfa-guard selective-gating test).
 *
 * Counts are non-negative integers and tenant-isolated: every query filters
 * `agency_id = {agency}` explicitly (belt-and-suspenders on top of the
 * `AgencyCreatorRelation` `BelongsToAgencyScope` global scope), so agency A
 * never counts agency B's data.
 *
 * Ownership: the Agencies module owns this even though
 * `pending_creator_applications` reads `Creator.application_status`
 * (Creators module) — the `agencies/{agency}/…` route group, the
 * `tenancy.agency` middleware, and `AgencyCreatorRelation` all live here,
 * so a read-only cross-module join is cleaner than splitting the endpoint
 * out to Creators.
 */
final class DashboardSummaryController
{
    public function __invoke(Request $request, Agency $agency): JsonResponse
    {
        return response()->json([
            'data' => [
                'creators_in_roster' => $this->rosterCount($agency),
                'pending_creator_applications' => $this->pendingApplicationsCount($agency),
                // Placeholders — hold their KPI slots with a stable `null`
                // contract until campaigns / payments ship (D-c1-4 / D-c1-6).
                'active_campaigns' => null,
                'payments_due' => null,
            ],
        ]);
    }

    /**
     * Distinct creators on the agency's active roster: a `roster` relation
     * to this agency, not blacklisted, whose creator is not soft-deleted.
     *
     * `is_blacklisted = false` (D-c1-7 + the chunk-1 plan-pause decision to
     * apply the exclusion to BOTH KPIs, not just pending — a blacklisted
     * roster member should not inflate "creators in roster"). Boolean-only;
     * scope-aware blacklisting (the `blacklist_scope`/`blacklist_type`
     * columns) is deferred to Sprint 7 — see docs/tech-debt.md.
     */
    private function rosterCount(Agency $agency): int
    {
        return AgencyCreatorRelation::query()
            ->where('agency_id', $agency->id)
            ->where('relationship_status', RelationshipStatus::Roster->value)
            ->where('is_blacklisted', false)
            // `whereHas('creator')` excludes relations whose creator is
            // soft-deleted (Creator's SoftDeletes global scope applies to
            // the EXISTS subquery).
            ->whereHas('creator')
            ->count();
    }

    /**
     * Distinct creators with (a) a relation to this agency (ANY
     * relationship_status — robust to external/edge cases, not hard-filtered
     * to roster per D-c1-7) and (b) `application_status = pending`.
     *
     * Excludes soft-deleted creators (the `Creator` query carries the
     * SoftDeletes global scope) and blacklisted relations (`is_blacklisted
     * = false`, consistent with the roster count). Self-signup creators with
     * no relation to this agency are correctly excluded — they aren't this
     * agency's applications. No blacklist-SCOPE logic (Sprint 7).
     */
    private function pendingApplicationsCount(Agency $agency): int
    {
        return Creator::query()
            ->where('application_status', ApplicationStatus::Pending->value)
            ->whereIn('id', function ($sub) use ($agency): void {
                $sub->from('agency_creator_relations')
                    ->select('creator_id')
                    ->where('agency_id', $agency->id)
                    ->where('is_blacklisted', false);
            })
            ->count();
    }
}
