<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Support;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Creators\Models\Creator;

/**
 * The "you can't act on a creator you don't have" gate, shared by every agency
 * surface that addresses a creator by route (D-2b-5).
 *
 * WHY THIS EXISTS: this check was hand-copied into four controllers — pool
 * membership, the pool picker, availability, and the roster detail page — each
 * with its own private `requireRosterRelation()`. All four asked only whether a
 * relation ROW EXISTS, with a comment stating that any status qualifies. That
 * was true until AH-051 added `ended`: a severed relation keeps its row, so all
 * four surfaces silently kept treating a disconnected creator as fully
 * available. Admin disconnect DELETES the pair's pool memberships precisely so
 * a severed relation leaves no trace, and yet the agency could re-add the
 * creator to a pool a second later — the deletion was decorative.
 *
 * Four copies is why it drifted, so there is now one. The status question is
 * deliberately NOT answered here: {@see self::requireExisting()} returns the
 * relation and each caller decides what its status permits, in the refusal
 * idiom that fits it (a write refuses with 422 and a specific code, a read
 * refuses with 403). Centralising the LOOKUP while keeping the DECISION at the
 * call site is what makes the next new status a visible, per-surface choice
 * rather than a silent fallthrough.
 */
final class AgencyCreatorRelationGuard
{
    /**
     * The relation between this agency and this creator, whatever its status.
     *
     * 404 (not 403) when there is no relation at all: that is the cross-tenant
     * existence case, and per docs/05-SECURITY-COMPLIANCE.md a caller must not
     * learn whether a creator it has no relationship with exists.
     *
     * ⚠ Returning a relation does NOT mean the caller may proceed — check the
     * status. A terminal state (`ended`, `declined`) is still a relation.
     */
    public static function requireExisting(Agency $agency, Creator $creator): AgencyCreatorRelation
    {
        // The explicit agency_id filter is belt-and-suspenders on top of the
        // BelongsToAgency global scope, as the original four copies had it.
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
