<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Http\Controllers;

use App\Modules\Agencies\Http\Requests\UpdateAgencyCreatorRelationRequest;
use App\Modules\Agencies\Http\Resources\AgencyCreatorDetailResource;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Creators\Models\Creator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * GET   /api/v1/agencies/{agency}/creators/{creator}  — the agency-side
 *       per-creator DETAIL view (Sprint 6 Chunk 2a, D-2a-1). The roster
 *       row-click (the D-c5-4 reversal) lands here.
 * PATCH /api/v1/agencies/{agency}/creators/{creator}  — edit the relation's
 *       rating + notes ONLY (D-2a-3). Admin/manager (D-2a-4).
 *
 * Tenancy / scope (mirrors {@see AgencyCreatorAvailabilityController} exactly):
 *   - Both routes sit under the `auth:web → tenancy.agency → tenancy` stack,
 *     so a non-member gets the 404 invisibility response before this runs.
 *   - The creator must be in THIS agency's roster — enforced by a
 *     RELATION-EXISTS check across ALL relationship statuses, else 404.
 *     Break-revert: dropping the relation check lets an agency read a
 *     non-related creator (the 404 test fails).
 *
 * The `platform_admin`-gated admin detail endpoint (GET /admin/creators/{creator})
 * is untouched — D-2a-1 does NOT relax that gate.
 */
final class AgencyCreatorDetailController
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    public function show(Request $request, Agency $agency, Creator $creator): JsonResponse
    {
        // Read stays any-member (D-2a-4): mirror the roster + availability
        // controllers' viewAny gate.
        Gate::authorize('viewAny', AgencyCreatorRelation::class);

        $relation = $this->requireRosterRelation($agency, $creator);

        return $this->detailResponse($relation, $creator, $request);
    }

    public function update(UpdateAgencyCreatorRelationRequest $request, Agency $agency, Creator $creator): JsonResponse
    {
        $relation = $this->requireRosterRelation($agency, $creator);

        // Write is admin/manager; staff view-only → 403 (D-2a-4).
        Gate::authorize('update', $relation);

        $validated = $request->validated();

        // Detect an ACTUAL notes change BEFORE the save, so the redacted notes
        // event fires only when the content really changed (the spot-check
        // pin) — never on a rating-only edit that re-sends the same notes.
        $notesProvided = array_key_exists('internal_notes', $validated);
        $notesChanged = $notesProvided && $relation->internal_notes !== $validated['internal_notes'];

        // Scope guard (D-2a-3): ONLY these two keys ever reach the model. A
        // stray blacklist / counter / relationship_status field in the payload
        // has no validation rule (see the request) and is filtered out here, so
        // it cannot mutate the relation.
        $updates = array_intersect_key($validated, array_flip(['internal_rating', 'internal_notes']));
        $relation->fill($updates)->save();
        // ↑ internal_rating is audit-allowlisted, so a rating change auto-emits
        //   the trait's before/after `agency_creator_relation.updated` diff.
        //   internal_notes is NOT allowlisted, so a notes change emits NO auto
        //   row — the redacted event below is its sole audit trail.

        if ($notesChanged) {
            // Redacted notes event (D-2a-5): records the FACT of change (actor
            // + timestamp resolved by AuditLogger from the active guard +
            // subject) with NO before/after content and NO metadata copy of
            // the notes. Break-revert: assert this row exists AND contains no
            // notes text.
            $this->auditLogger->log(
                action: AuditAction::AgencyCreatorRelationNotesUpdated,
                subject: $relation,
            );
        }

        return $this->detailResponse($relation, $creator, $request);
    }

    private function detailResponse(AgencyCreatorRelation $relation, Creator $creator, Request $request): JsonResponse
    {
        $creator->loadMissing(['user', 'socialAccounts', 'portfolioItems']);
        $relation->setRelation('creator', $creator);

        return (new AgencyCreatorDetailResource($relation))->response($request);
    }

    /**
     * 404 unless the creator is in this agency's roster (any relationship
     * status). Returns the relation so the caller can read/write it. The
     * belt-and-suspenders explicit agency_id filter sits on top of the
     * BelongsToAgency global scope, mirroring the roster + availability
     * controllers.
     */
    private function requireRosterRelation(Agency $agency, Creator $creator): AgencyCreatorRelation
    {
        $relation = AgencyCreatorRelation::query()
            ->where('agency_id', $agency->id)
            ->where('creator_id', $creator->id)
            ->first();

        if ($relation === null) {
            abort(404);
        }

        return $relation;
    }
}
