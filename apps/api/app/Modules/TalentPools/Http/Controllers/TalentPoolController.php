<?php

declare(strict_types=1);

namespace App\Modules\TalentPools\Http\Controllers;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Brands\Models\Brand;
use App\Modules\TalentPools\Http\Requests\CreateTalentPoolRequest;
use App\Modules\TalentPools\Http\Requests\UpdateTalentPoolRequest;
use App\Modules\TalentPools\Http\Resources\TalentPoolResource;
use App\Modules\TalentPools\Models\TalentPool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Talent-pool CRUD (Sprint 6 Chunk 2b, D-2b-6) — mirrors BrandController
 * verbatim: index / store / show / update / destroy (archive) + restore,
 * each non-index method composing
 *   assertBelongsToAgency($pool, $agency)  (404-not-403 cross-tenant check)
 *   → Gate::authorize(...)
 *   → Audit::log(...).
 *
 * The one structural difference from brands: pools have NO status column
 * (D-2b-1), so archive is a pure soft-delete (no status flip) and the list's
 * active/archived/all filter keys off the trashed state alone.
 */
final class TalentPoolController
{
    /**
     * GET /api/v1/agencies/{agency}/talent-pools
     *
     * Lists the agency's pools with membership COUNTS (D-2b-7) — not member
     * previews. `?status`:
     *   - 'active'   (default) — non-archived pools
     *   - 'archived'           — only archived (soft-deleted) pools
     *   - 'all'                — both
     */
    public function index(Request $request, Agency $agency): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', TalentPool::class);

        $status = $request->query('status', 'active');

        $query = TalentPool::query()->withCount('creators')->with('brand');

        if ($status === 'archived') {
            $query->onlyTrashed();
        } elseif ($status === 'all') {
            $query->withTrashed();
        }

        $pools = $query->orderBy('name')->paginate(25);

        return TalentPoolResource::collection($pools);
    }

    /**
     * POST /api/v1/agencies/{agency}/talent-pools
     */
    public function store(CreateTalentPoolRequest $request, Agency $agency): JsonResponse
    {
        Gate::authorize('create', TalentPool::class);

        $validated = $request->validated();

        $pool = TalentPool::query()->create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'brand_id' => $this->resolveBrandId($validated['brand_id'] ?? null, $agency),
            'created_by_user_id' => $request->user()?->id,
        ]);

        Audit::log(
            action: AuditAction::TalentPoolCreated,
            subject: $pool,
            after: $pool->toArray(),
        );

        return (new TalentPoolResource($pool->loadCount('creators')->load('brand')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * GET /api/v1/agencies/{agency}/talent-pools/{talent_pool}
     *
     * Cross-tenant protection mirrors BrandController::show — 404 (not 403)
     * to avoid confirming whether the ULID is valid.
     */
    public function show(Request $request, Agency $agency, TalentPool $talentPool): TalentPoolResource
    {
        $this->assertBelongsToAgency($talentPool, $agency);
        Gate::authorize('view', $talentPool);

        return new TalentPoolResource($talentPool->loadCount('creators')->load('brand'));
    }

    /**
     * PATCH /api/v1/agencies/{agency}/talent-pools/{talent_pool}
     */
    public function update(UpdateTalentPoolRequest $request, Agency $agency, TalentPool $talentPool): TalentPoolResource
    {
        $this->assertBelongsToAgency($talentPool, $agency);
        Gate::authorize('update', $talentPool);

        $validated = $request->validated();
        $before = $talentPool->toArray();

        $updates = [];
        if (array_key_exists('name', $validated)) {
            $updates['name'] = $validated['name'];
        }
        if (array_key_exists('description', $validated)) {
            $updates['description'] = $validated['description'];
        }
        if (array_key_exists('brand_id', $validated)) {
            $updates['brand_id'] = $this->resolveBrandId($validated['brand_id'], $agency);
        }

        $talentPool->update($updates);

        Audit::log(
            action: AuditAction::TalentPoolUpdated,
            subject: $talentPool,
            before: $before,
            after: $talentPool->fresh()?->toArray() ?? [],
        );

        return new TalentPoolResource(($talentPool->fresh() ?? $talentPool)->loadCount('creators')->load('brand'));
    }

    /**
     * DELETE /api/v1/agencies/{agency}/talent-pools/{talent_pool}
     *
     * "Archive" — a pure soft-delete (D-2b-1: no status column). The pool's
     * membership rows survive (talent_pool_id cascade fires only on a HARD
     * delete), so an accidental archive is fully recoverable via restore
     * (D-2b-3). Admin or manager (staff 403).
     */
    public function destroy(Request $request, Agency $agency, TalentPool $talentPool): JsonResponse
    {
        $this->assertBelongsToAgency($talentPool, $agency);
        Gate::authorize('archive', $talentPool);

        $before = $talentPool->toArray();

        $talentPool->delete();

        Audit::log(
            action: AuditAction::TalentPoolArchived,
            subject: $talentPool,
            before: $before,
            after: $talentPool->fresh()?->toArray() ?? [],
        );

        return (new TalentPoolResource(
            (TalentPool::withTrashed()->find($talentPool->id) ?? $talentPool)->loadCount('creators')->load('brand'),
        ))
            ->response()
            ->setStatusCode(200);
    }

    /**
     * POST /api/v1/agencies/{agency}/talent-pools/{talent_pool}/restore
     *
     * Restores an archived pool (clears deleted_at). The default Eloquent
     * binding excludes soft-deleted rows, so we look the pool up explicitly
     * via the ULID (HasUlid's route key). Admin or manager (staff 403).
     *
     * Idempotent (#6): restoring an already-active pool is a 200 no-op (no
     * audit, no DB write), matching BrandController::restore.
     */
    public function restore(Request $request, Agency $agency, string $talentPool): JsonResponse
    {
        $pool = TalentPool::withTrashed()->where('ulid', $talentPool)->first();
        if ($pool === null) {
            abort(404);
        }
        $this->assertBelongsToAgency($pool, $agency);
        Gate::authorize('restore', $pool);

        if ($pool->deleted_at === null) {
            return (new TalentPoolResource($pool->loadCount('creators')->load('brand')))
                ->response()
                ->setStatusCode(200);
        }

        $before = $pool->toArray();

        $pool->restore();

        Audit::log(
            action: AuditAction::TalentPoolRestored,
            subject: $pool,
            before: $before,
            after: $pool->fresh()?->toArray() ?? [],
        );

        return (new TalentPoolResource(($pool->fresh() ?? $pool)->loadCount('creators')->load('brand')))
            ->response()
            ->setStatusCode(200);
    }

    /**
     * Resolve an optional brand ULID (the API id) to the integer FK. Returns
     * null for an agency-wide pool. The request rule already constrains the
     * ULID to a brand owned by THIS agency, so a stray brand cannot be
     * attached cross-tenant.
     */
    private function resolveBrandId(?string $brandUlid, Agency $agency): ?int
    {
        if ($brandUlid === null) {
            return null;
        }

        return Brand::query()
            ->where('ulid', $brandUlid)
            ->where('agency_id', $agency->id)
            ->value('id');
    }

    /**
     * Belt-and-suspenders cross-tenant check (mirrors BrandController).
     * SubstituteBindings runs before tenancy.agency sets TenancyContext, so
     * BelongsToAgencyScope cannot scope the binding. Returns 404 (not 403) to
     * avoid leaking whether the ULID is valid — docs/05-SECURITY-COMPLIANCE.md §7.
     */
    private function assertBelongsToAgency(TalentPool $pool, Agency $agency): void
    {
        if ($pool->agency_id !== $agency->id) {
            abort(404);
        }
    }
}
