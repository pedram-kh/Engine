<?php

declare(strict_types=1);

namespace App\Modules\Boards\Listeners;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Boards\Services\BoardCardService;
use App\Modules\Boards\Services\BoardService;
use App\Modules\Campaigns\Events\AssignmentTransitioned;
use App\Modules\Campaigns\Listeners\CreateMessageThread;

/**
 * The 6th consumer of {@see AssignmentTransitioned} (Sprint 12 Chunk 1, D-5) —
 * clones {@see CreateMessageThread} verbatim.
 *
 * When a creator is invited to a campaign, provision the board card so the
 * assignment appears on the campaign's Kanban from day one. Registered BEFORE
 * {@see BoardAutomationListener} (D-7): invite fires both this listener and the
 * `invited → Invited` automation off the same event, so the card must exist
 * first. The automation is ALSO a no-op when the card is missing (belt +
 * suspenders), so a registration-order slip can't drop the move.
 *
 * Idempotent: the create is delegated to {@see BoardCardService::forAssignment()}
 * (firstOrCreate keyed on the `assignment_id` UNIQUE), so a re-invite or a card
 * that already exists (e.g. lazily healed on a GET) is a no-op. The board is
 * ensured first ({@see BoardService::ensureBoard()}) because a card needs a
 * board + columns; the lazy GET heal ({@see BoardService::forCampaign()}) heals
 * any card-less assignment that predates this listener — so no backfill
 * migration is needed (D-4/D-5).
 */
final class CreateBoardCard
{
    public function __construct(
        private readonly BoardService $boards,
        private readonly BoardCardService $cards,
    ) {}

    public function handle(AssignmentTransitioned $event): void
    {
        if ($event->action !== AuditAction::AssignmentInvited) {
            return;
        }

        $assignment = $event->assignment;
        $campaign = $assignment->campaign;
        if ($campaign === null) {
            return;
        }

        $board = $this->boards->ensureBoard($campaign);
        $this->cards->forAssignment($board, $assignment);
    }
}
