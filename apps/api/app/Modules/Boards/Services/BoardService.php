<?php

declare(strict_types=1);

namespace App\Modules\Boards\Services;

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Boards\Models\Board;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Provisions + heals a campaign's board (Sprint 12 Chunk 1, D-4).
 *
 * Lazy provisioning on first board GET, NO backfill migration: the board (+ its
 * default columns + automations via {@see BoardProvisioningService}) is
 * firstOrCreate'd on the `boards.campaign_id` UNIQUE, and cards are healed for
 * any assignment lacking one (keyed on the `board_cards.assignment_id` UNIQUE).
 * This heals every existing board-less campaign with zero migration — nothing
 * reads a board today (coming-soon empty state), so the lazy heal is trivially
 * safe.
 *
 * The global BelongsToAgency scope is bypassed (the named, greppable construct
 * per docs/security/tenancy.md §5): provisioning is idempotent infrastructure
 * keyed on the UNIQUE, `agency_id` is ALWAYS set explicitly from the
 * already-resolved campaign, and the caller has resolved the campaign under its
 * own scope (no cross-tenant read). Bypassing makes firstOrCreate see the
 * canonical row regardless of ambient context.
 */
final class BoardService
{
    public function __construct(
        private readonly BoardProvisioningService $provisioning,
        private readonly BoardCardService $cards,
    ) {}

    /**
     * Ensure the campaign has a board with default columns + automations. Does
     * NOT heal cards — used by the invite listener (which heals exactly one card
     * itself) to avoid sweeping every assignment on each invite.
     */
    public function ensureBoard(Campaign $campaign): Board
    {
        try {
            $board = Board::query()
                ->withoutGlobalScope(BelongsToAgencyScope::class)
                ->firstOrCreate(
                    ['campaign_id' => $campaign->id],
                    ['agency_id' => $campaign->agency_id],
                );
        } catch (UniqueConstraintViolationException) {
            $board = Board::query()
                ->withoutGlobalScope(BelongsToAgencyScope::class)
                ->where('campaign_id', $campaign->id)
                ->firstOrFail();
        }

        // Provision defaults only on first creation — an existing board is
        // already seeded, so the per-poll board GET stays cheap. (Re-seeding a
        // customized board is "reset to defaults", a Chunk 2 surface.)
        // provisionDefaults is itself idempotent (belt) if called again.
        if ($board->wasRecentlyCreated) {
            $this->provisioning->provisionDefaults($board);
        }

        return $board;
    }

    /**
     * The board-GET entry point: ensure the board + heal a card for every
     * assignment lacking one (D-4/D-5). Returns the board.
     */
    public function forCampaign(Campaign $campaign): Board
    {
        $board = $this->ensureBoard($campaign);

        CampaignAssignment::query()
            ->withoutGlobalScope(BelongsToAgencyScope::class)
            ->where('campaign_id', $campaign->id)
            ->whereDoesntHave('boardCard')
            ->get()
            ->each(fn (CampaignAssignment $assignment) => $this->cards->forAssignment($board, $assignment));

        return $board;
    }
}
