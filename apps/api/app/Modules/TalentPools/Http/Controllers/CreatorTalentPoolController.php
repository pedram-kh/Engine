<?php

declare(strict_types=1);

namespace App\Modules\TalentPools\Http\Controllers;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Creators\Models\Creator;
use App\Modules\TalentPools\Http\Resources\TalentPoolPickerResource;
use App\Modules\TalentPools\Models\TalentPool;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * GET /api/v1/agencies/{agency}/creators/{creator}/talent-pools — the pool
 * picker's fetch (Sprint 6 Chunk 2b, D-2b-9). Lists the agency's (active)
 * pools, each flagged `is_member` for THIS creator, so the dialog can render a
 * per-pool toggle reflecting current membership.
 *
 * No N+1 (the honest-deviation fetch-shape flag): `is_member` is computed in a
 * SINGLE query via `withExists`, a correlated subquery scoped to the creator —
 * not one membership query per pool.
 *
 * Tenancy: composes the creator's relation-exists gate (requireRosterRelation,
 * D-2b-5) — you can only see/curate pool membership for a creator you have.
 * Read is any agency member (viewAny); the toggle WRITES are admin/manager,
 * gated on the membership endpoints themselves.
 */
final class CreatorTalentPoolController
{
    public function index(Request $request, Agency $agency, Creator $creator): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', TalentPool::class);

        $this->requireRosterRelation($agency, $creator);

        $pools = TalentPool::query()
            ->withExists(['creators as creators_exists' => function ($query) use ($creator): void {
                $query->where('creators.id', $creator->id);
            }])
            ->with('brand')
            ->orderBy('name')
            ->get();

        return TalentPoolPickerResource::collection($pools);
    }

    /**
     * 404 unless the creator is in this agency's roster (any relationship
     * status) — the requireRosterRelation pattern (D-2b-5).
     */
    private function requireRosterRelation(Agency $agency, Creator $creator): void
    {
        $hasRelation = AgencyCreatorRelation::query()
            ->where('agency_id', $agency->id)
            ->where('creator_id', $creator->id)
            ->exists();

        if (! $hasRelation) {
            abort(404);
        }
    }
}
