<?php

declare(strict_types=1);

namespace App\Modules\Boards\Services;

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Boards\Http\Resources\BoardCardResource;
use App\Modules\Boards\Models\Board;
use App\Modules\Campaigns\Enums\CampaignApplicationStatus;
use App\Modules\Campaigns\Models\CampaignApplication;
use Illuminate\Http\Request;

/**
 * PL-1.2 (D3) — did a creator on a wave board's Invited column get there by applying or by being invited?
 *
 * There is no stored origin on `campaign_assignments`. What exists is the application: accepting an application
 * writes the application as `accepted` and then creates the ordinary `invited` assignment (an offer the creator
 * still has to answer), in one transaction. A direct invite to a creator with a pending application settles that
 * application the same way (`CampaignApplicationDecisionService::settlePendingApplication`), on purpose: the
 * invitation is what the application asked for. So "an accepted application exists for this campaign and creator"
 * is the origin, and it is the same for every existing row.
 *
 * The one case it cannot tell apart is a creator whose application was accepted, who declined, and who was later
 * re-invited directly: that card says "Application accepted". Rare, and recorded in the review file.
 *
 * Read once per request and handed to {@see BoardCardResource} through a request
 * attribute, so a board of N cards costs one query, and only a wave board pays it.
 */
final class WaveInviteOrigin
{
    public const string ATTRIBUTE = 'boards.applied_creator_ids';

    /**
     * Store the ids of the creators with an accepted application on the board's campaign, for the card resources.
     */
    public static function remember(Request $request, Board $board): void
    {
        $request->attributes->set(self::ATTRIBUTE, self::appliedCreatorIds($board));
    }

    /**
     * @return list<int>
     */
    public static function appliedCreatorIds(Board $board): array
    {
        /** @var list<int> $ids */
        $ids = CampaignApplication::query()
            ->withoutGlobalScope(BelongsToAgencyScope::class)
            ->where('campaign_applications.agency_id', $board->agency_id)
            ->where('campaign_applications.campaign_id', $board->campaign_id)
            ->where('campaign_applications.status', CampaignApplicationStatus::Accepted->value)
            ->pluck('campaign_applications.creator_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return $ids;
    }
}
