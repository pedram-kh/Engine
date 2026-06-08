<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Http\Controllers;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Campaigns\Enums\DraftReviewStatus;
use App\Modules\Campaigns\Http\Resources\CampaignDraftListItemResource;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignDraft;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Agency-side campaign-wide draft listing for the Drafts tab.
 *
 * Read-only, view-gated (`Gate::authorize('view', $campaign)`). Returns every
 * `campaign_drafts` row for the campaign (all versions, flat) via a two-hop
 * join through `campaign_assignments` — no `campaign_id` on drafts.
 *
 * Tech-debt trigger: if this query is slow at volume, denormalize `campaign_id`
 * onto `campaign_drafts` + add an index; do not denormalize preemptively.
 */
final class CampaignDraftController
{
    /**
     * GET /api/v1/agencies/{agency}/campaigns/{campaign}/drafts
     */
    public function index(Request $request, Agency $agency, Campaign $campaign): JsonResponse
    {
        $this->assertBelongsToAgency($campaign, $agency);
        Gate::authorize('view', $campaign);

        $perPage = max(1, min((int) $request->integer('per_page', 25), 100));

        $query = CampaignDraft::query()
            ->select('campaign_drafts.*')
            ->join('campaign_assignments', 'campaign_drafts.assignment_id', '=', 'campaign_assignments.id')
            ->where('campaign_assignments.campaign_id', $campaign->id)
            ->where('campaign_assignments.agency_id', $agency->id);

        $this->applyReviewStatusFilter($query, $request);

        $paginator = $query
            ->with(['assignment.creator:id,ulid,display_name'])
            ->orderBy('campaign_assignments.id')
            ->orderByDesc('campaign_drafts.version')
            ->paginate($perPage)
            ->withQueryString();

        return response()->json([
            'data' => CampaignDraftListItemResource::collection($paginator->items())->resolve($request),
            'meta' => [
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * @param  Builder<CampaignDraft>  $query
     */
    private function applyReviewStatusFilter(Builder $query, Request $request): void
    {
        $statusInput = $request->query('review_status');
        if (! is_string($statusInput) || $statusInput === '') {
            return;
        }

        $status = DraftReviewStatus::tryFrom($statusInput);
        if ($status === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where('campaign_drafts.review_status', $status->value);
    }

    private function assertBelongsToAgency(Campaign $campaign, Agency $agency): void
    {
        if ($campaign->agency_id !== $agency->id) {
            abort(404);
        }
    }
}
