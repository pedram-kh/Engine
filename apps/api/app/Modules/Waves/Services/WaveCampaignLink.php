<?php

declare(strict_types=1);

namespace App\Modules\Waves\Services;

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Boards\Support\BoardDefaults;
use App\Modules\Waves\Models\Wave;
use Illuminate\Support\Facades\DB;

/**
 * PL-1.2 (P5, Q3) — the one place that decides whether a campaign's wave link is locked, and the one lock that
 * serialises a link change with a card move.
 *
 * The lock reads WHERE THE CARDS ARE NOW and nothing else: it is locked while at least one card of the campaign's
 * board sits in a working column (`selected`, `filming`, `submitted`, `in_edits`, `client_review`, `approved`).
 * A card in Applied, Invited or Dropped does not lock, and `board_card_movements` is never read: a creator who was
 * selected and then moved back no longer locks. A campaign with no board is unlocked.
 */
final class WaveCampaignLink
{
    public function isLocked(int $campaignId): bool
    {
        $cardInWork = DB::table('board_cards as c')
            ->join('boards as b', 'b.id', '=', 'c.board_id')
            ->join('board_columns as col', 'col.id', '=', 'c.column_id')
            ->where('b.campaign_id', $campaignId)
            ->whereIn('col.stage_key', BoardDefaults::waveWorkingStages())
            ->exists();
        if ($cardInWork) {
            return true;
        }

        // PL-3.1 (P10): also locked while the campaign's wave has at least one creator in its Creators step. Read NOW, so
        // removing the last creator unlocks. Evaluated only after the card check above, and the join starts at `waves`, so a
        // campaign no wave uses answers from one indexed lookup of `waves.campaign_id`.
        return DB::table('wave_creators as wc')
            ->join('waves as w', 'w.id', '=', 'wc.wave_id')
            ->where('w.campaign_id', $campaignId)
            ->whereNull('w.deleted_at')
            ->exists();
    }

    /**
     * `SELECT ... FOR UPDATE` on the live wave linked to this campaign, if there is one. Both a link change
     * (`WaveService`) and a card move that can change the lock take it, so whichever commits first wins and the
     * other reads the result. SQLite ignores row locks; the PostgreSQL run and a `for update` SQL assertion are
     * what prove it.
     */
    public function lockWaveOfCampaign(int $campaignId): ?Wave
    {
        return Wave::query()
            ->withoutGlobalScope(BelongsToAgencyScope::class)
            ->where('campaign_id', $campaignId)
            ->lockForUpdate()
            ->first();
    }
}
