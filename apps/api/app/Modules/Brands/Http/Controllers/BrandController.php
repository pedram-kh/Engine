<?php

declare(strict_types=1);

namespace App\Modules\Brands\Http\Controllers;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Brands\Enums\BrandStatus;
use App\Modules\Brands\Http\Requests\CreateBrandRequest;
use App\Modules\Brands\Http\Requests\UpdateBrandRequest;
use App\Modules\Brands\Http\Resources\BrandResource;
use App\Modules\Brands\Models\Brand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

final class BrandController
{
    /**
     * GET /api/v1/agencies/{agency}/brands
     *
     * Lists brands for the agency. Default returns active brands only.
     * Supported `?status` values:
     *   - 'active'   (default) — only active brands
     *   - 'archived'           — only archived (soft-deleted) brands
     *   - 'all'                — both active and archived brands
     * Only one role — any membership — can view.
     */
    public function index(Request $request, Agency $agency): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Brand::class);

        $status = $request->query('status', 'active');

        $query = Brand::query();

        if ($status === 'archived') {
            $query->withTrashed()->where('status', BrandStatus::Archived->value);
        } elseif ($status === 'all') {
            // Include soft-deleted rows so archived brands surface in the
            // unified view; the SPA's status chip discriminates client-side.
            $query->withTrashed();
        } else {
            $query->where('status', BrandStatus::Active->value);
        }

        $brands = $query->orderBy('name')->paginate(25);

        return BrandResource::collection($brands);
    }

    /**
     * POST /api/v1/agencies/{agency}/brands
     */
    public function store(CreateBrandRequest $request, Agency $agency): JsonResponse
    {
        Gate::authorize('create', Brand::class);

        $brand = Brand::query()->create($request->validated());

        Audit::log(
            action: AuditAction::BrandCreated,
            subject: $brand,
            after: $brand->toArray(),
        );

        return (new BrandResource($brand))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * GET /api/v1/agencies/{agency}/brands/{brand}
     *
     * Note on cross-tenant protection: `SubstituteBindings` resolves the
     * `{brand}` model before `tenancy.agency` sets TenancyContext, so
     * BelongsToAgencyScope cannot filter during binding. We enforce the
     * ownership constraint explicitly here — 404 rather than 403 to avoid
     * confirming whether the ULID is valid (non-fingerprinting per
     * docs/05-SECURITY-COMPLIANCE.md §7).
     */
    public function show(Request $request, Agency $agency, Brand $brand): BrandResource
    {
        $this->assertBelongsToAgency($brand, $agency);
        Gate::authorize('view', $brand);

        return new BrandResource($brand);
    }

    /**
     * PATCH /api/v1/agencies/{agency}/brands/{brand}
     */
    public function update(UpdateBrandRequest $request, Agency $agency, Brand $brand): BrandResource
    {
        $this->assertBelongsToAgency($brand, $agency);
        Gate::authorize('update', $brand);

        $before = $brand->toArray();

        $brand->update($request->validated());

        Audit::log(
            action: AuditAction::BrandUpdated,
            subject: $brand,
            before: $before,
            after: $brand->fresh()?->toArray() ?? [],
        );

        return new BrandResource($brand->fresh() ?? $brand);
    }

    /**
     * DELETE /api/v1/agencies/{agency}/brands/{brand}
     *
     * "Archive" action — sets status to `archived` and sets deleted_at so
     * the default query scope excludes this brand. The semantic "archive"
     * is backed by soft-delete; the `status` column carries the named state.
     *
     * Returns 200 with the updated resource so the SPA can reflect the
     * new state immediately without a follow-up GET.
     *
     * Requires agency_admin or agency_manager role.
     */
    public function destroy(Request $request, Agency $agency, Brand $brand): JsonResponse
    {
        $this->assertBelongsToAgency($brand, $agency);
        Gate::authorize('archive', $brand);

        $before = $brand->toArray();

        $brand->update(['status' => BrandStatus::Archived]);
        $brand->delete(); // soft delete — sets deleted_at

        Audit::log(
            action: AuditAction::BrandArchived,
            subject: $brand,
            before: $before,
            after: array_merge($brand->fresh()?->toArray() ?? [], ['status' => BrandStatus::Archived->value]),
        );

        return (new BrandResource($brand->withTrashed()->find($brand->id) ?? $brand))
            ->response()
            ->setStatusCode(200);
    }

    /**
     * Belt-and-suspenders cross-tenant check.
     *
     * `SubstituteBindings` runs before `tenancy.agency` sets TenancyContext,
     * so `BelongsToAgencyScope` cannot scope the brand lookup during binding.
     * This explicit check closes that window. Returns 404 (not 403) to avoid
     * leaking whether the ULID is valid — docs/05-SECURITY-COMPLIANCE.md §7.
     */
    private function assertBelongsToAgency(Brand $brand, Agency $agency): void
    {
        if ($brand->agency_id !== $agency->id) {
            abort(404);
        }
    }
}
