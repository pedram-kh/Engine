<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Http\Controllers;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Services\BrandExclusivity;
use App\Modules\Creators\Models\Creator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * AH-120 — the invite dialog's non-blocking warning: "this creator is selected
 * on <campaign> and cannot apply to this brand's jobs".
 *
 *   POST /api/v1/agencies/{agency}/campaigns/{campaign}/exclusivity-conflicts
 *
 * ⚠ READ-ONLY despite the POST (the creator list rides in the body). It writes,
 * audits and notifies nothing; see docs/security/tenancy.md. `invite`-gated, the
 * same ability as the dialog it serves. An agency invite is NEVER blocked
 * (ruling 4): this only informs.
 *
 * Response: `{data: {<creator ulid>: {blocking_campaign: {id, name}}}}`, with
 * creators who are not blocked simply absent. While the flag is OFF, `{data: []}`.
 * Unknown creator ULIDs are omitted, not an error.
 */
final class CampaignExclusivityConflictController
{
    private const int MAX_CREATORS = 100;

    public function index(Request $request, Agency $agency, Campaign $campaign, BrandExclusivity $exclusivity): JsonResponse
    {
        if ($campaign->agency_id !== $agency->id) {
            abort(404);
        }

        Gate::authorize('invite', $campaign);

        $validated = $request->validate([
            'creator_ids' => ['required', 'array', 'max:'.self::MAX_CREATORS],
            'creator_ids.*' => ['string', 'distinct'],
        ]);

        /** @var array<int, string> $ulids */
        $ulids = $validated['creator_ids'];

        // The flag is `conflictsFor()`'s to answer: OFF is an empty map there.
        $creators = Creator::query()->whereIn('ulid', array_values($ulids))->pluck('ulid', 'id')->all();

        $conflicts = $exclusivity->conflictsFor($campaign, array_map(intval(...), array_keys($creators)));

        $data = [];
        foreach ($conflicts as $creatorId => $blocking) {
            $data[(string) $creators[$creatorId]] = [
                'blocking_campaign' => ['id' => $blocking['campaign_ulid'], 'name' => $blocking['campaign_name']],
            ];
        }

        return response()->json(['data' => $data]);
    }
}
