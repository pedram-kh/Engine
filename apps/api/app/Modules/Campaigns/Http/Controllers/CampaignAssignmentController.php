<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Http\Controllers;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Campaigns\Http\Resources\CampaignAssignmentResource;
use App\Modules\Campaigns\Models\Campaign;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Read-only listing of a campaign's assignments for the agency-side Creators
 * tab (Sprint 8 Chunk 1). Inviting + mutating assignments land in Chunk 2 —
 * this endpoint is complete + agency-scoped + viewable by any member; it
 * simply returns an empty page until Chunk 2 populates assignments.
 */
final class CampaignAssignmentController
{
    /**
     * GET /api/v1/agencies/{agency}/campaigns/{campaign}/assignments
     */
    public function index(Request $request, Agency $agency, Campaign $campaign): JsonResponse
    {
        $this->assertBelongsToAgency($campaign, $agency);
        Gate::authorize('view', $campaign);

        $perPage = max(1, min((int) $request->integer('per_page', 25), 100));

        $paginator = $campaign->assignments()
            ->where('campaign_assignments.agency_id', $agency->id)
            ->with('creator:id,ulid,display_name')
            ->orderByDesc('campaign_assignments.id')
            ->paginate($perPage)
            ->withQueryString();

        return response()->json([
            'data' => CampaignAssignmentResource::collection($paginator->items())->resolve($request),
            'meta' => [
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    private function assertBelongsToAgency(Campaign $campaign, Agency $agency): void
    {
        if ($campaign->agency_id !== $agency->id) {
            abort(404);
        }
    }
}
