<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Services;

use App\Modules\Boards\Models\Board;
use App\Modules\Strategy\Features\CreatorsScriptsEnabled;
use App\Modules\Strategy\Models\WaveCreator;
use Illuminate\Http\Request;

/**
 * The derived "In wave" marker of a wave board (PL-3.1 plan 1.6, 3.3): true for a creator who has a place in the Creators step
 * of the wave linked to the board's campaign. Never stored. ONE query per wave-board load, kept in a request attribute (the
 * `WaveInviteOrigin` pattern), and asked ONLY on a wave board with all three flags on: a normal board's payload and query
 * count do not change.
 */
final class WaveCreatorMarker
{
    public const ATTRIBUTE = 'strategy.in_wave_creator_ids';

    /** Read once for the board's cards; a no-op (no query beyond the flag read) when the sprint flag is off. */
    public static function remember(Request $request, Board $board): void
    {
        if (! CreatorsScriptsEnabled::active()) {
            return;
        }

        $request->attributes->set(self::ATTRIBUTE, self::creatorIds($board->agency_id, $board->campaign_id));
    }

    /**
     * @return list<int>|null null when the sprint flag is off (the key is then NOT emitted at all)
     */
    public static function creatorIdsFor(Request $request): ?array
    {
        $ids = $request->attributes->get(self::ATTRIBUTE);

        return is_array($ids) ? array_values(array_map(static fn (mixed $i): int => (int) $i, $ids)) : null;
    }

    /**
     * The creators of the wave linked to a campaign, or null when the sprint flag is off.
     *
     * @return list<int>|null
     */
    public static function forCampaign(int $agencyId, int $campaignId): ?array
    {
        return CreatorsScriptsEnabled::active() ? self::creatorIds($agencyId, $campaignId) : null;
    }

    /**
     * @return list<int>
     */
    private static function creatorIds(int $agencyId, int $campaignId): array
    {
        /** @var list<int> $ids */
        $ids = WaveCreator::query()->withoutGlobalScopes()
            ->join('waves', 'waves.id', '=', 'wave_creators.wave_id')
            ->where('waves.agency_id', $agencyId)->where('waves.campaign_id', $campaignId)->whereNull('waves.deleted_at')
            ->pluck('wave_creators.creator_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return $ids;
    }
}
