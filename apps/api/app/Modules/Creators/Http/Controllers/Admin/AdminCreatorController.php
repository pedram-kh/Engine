<?php

declare(strict_types=1);

namespace App\Modules\Creators\Http\Controllers\Admin;

use App\Modules\Creators\Http\Resources\CreatorResource;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Services\CompletenessScoreCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin-facing read-only view of a single Creator.
 *
 * Sprint 3 Chunk 3 sub-step 9. Path-scoped admin tooling (per
 * docs/security/tenancy.md § 4 category C — "path-scoped admin
 * tooling" — the new entry for this route is added in sub-step 12's
 * tenancy.md fix-ups).
 *
 * The endpoint reuses CreatorResource::withAdmin() so the response
 * shape is symmetric with the creator-self view (Chunk 1 tech-debt
 * entry 4 closure): same attributes block, plus an `admin_attributes`
 * block carrying rejection_reason + kyc_verifications history (PII
 * stripped — decision_data + failure_reason are NOT surfaced; admin
 * drill-in lands at a separate endpoint in Sprint 4+).
 *
 * Per-field admin EDIT (PATCH /api/v1/admin/creators/{creator}) is
 * deferred to Chunk 4 per pause-condition-6 (the policy method
 * `CreatorPolicy::adminUpdate()` already exists from Chunk 1; the
 * endpoint + audit + idempotency land alongside the approve/reject
 * workflow in Sprint 4).
 */
final class AdminCreatorController
{
    public function __construct(
        private readonly CompletenessScoreCalculator $calculator,
    ) {}

    public function show(Request $request, Creator $creator): JsonResponse
    {
        $this->authorize($request, 'view', $creator);

        $creator->loadMissing(['socialAccounts', 'portfolioItems', 'kycVerifications']);

        return (new CreatorResource($creator, $this->calculator))
            ->withAdmin(true)
            ->response($request);
    }

    private function authorize(Request $request, string $ability, Creator $creator): void
    {
        $user = $request->user();
        if ($user === null) {
            abort(401);
        }
        if (! $user->can($ability, $creator)) {
            abort(403);
        }
    }
}
