<?php

declare(strict_types=1);

namespace App\Modules\Boards\Services;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Boards\Enums\MovementTrigger;
use App\Modules\Boards\Exceptions\BoardRuleViolation;
use App\Modules\Boards\Models\BoardCard;
use App\Modules\Boards\Models\BoardCardMovement;
use App\Modules\Boards\Models\BoardColumn;
use App\Modules\Boards\Support\BoardDefaults;
use App\Modules\Identity\Models\User;
use App\Modules\Waves\Services\WaveCampaignLink;
use Illuminate\Support\Facades\DB;

/**
 * The MANUAL card-move path (Sprint 12 Chunk 1, D-8/D-9). Per
 * docs/10-BOARD-AUTOMATION.md §4.4 + §5.4.
 *
 * Board state is a VISUALIZATION layer: a manual move records the FACT but
 * drives NO business logic. This service writes BOTH trails (D-9) — one
 * `audit_logs` row (`board.card_moved_manually`) AND one `board_card_movements`
 * row (`triggered_by = user`) — in a single transaction, and has STRUCTURALLY
 * NO reference to the assignment state machine (the single status authority —
 * no controller flips status directly). A manual move to "Paid" does NOT release
 * payment. (Pinned by the manual-move negative + source-inspection tests.)
 *
 * This is also the re-home primitive for the column-delete safeguard (§14.3):
 * cards re-home as manual movements before their column is deleted.
 */
final class BoardCardMoveService
{
    public function move(BoardCard $card, BoardColumn $targetColumn, User $actor, ?string $reason = null): BoardCard
    {
        // Same-column move is a no-op (no movement, no audit row).
        if ($card->column_id === $targetColumn->id) {
            return $card;
        }

        $working = BoardDefaults::waveWorkingStages();
        $toWorking = $targetColumn->stage_key !== null && in_array($targetColumn->stage_key, $working, true);

        // PL-1.2 (Q4) — on a wave board only a creator who has Accepted can enter a working column. Dropped and
        // every move back are free. The rule is HERE, in the one write path, so no route can bypass it.
        if ($toWorking && $card->assignment?->status->countsAsSelected() !== true) {
            throw BoardRuleViolation::selectionRequiresAcceptance();
        }

        $fromStage = $card->column?->stage_key;
        $fromWorking = $fromStage !== null && in_array($fromStage, $working, true);

        DB::transaction(function () use ($card, $targetColumn, $actor, $reason, $toWorking, $fromWorking): void {
            // PL-1.2 (Q3) — the link lock reads where cards are NOW, so a move INTO the working set (can lock) and a
            // move OUT of it (can unlock) both take the wave row lock before the card changes. A move between two
            // working columns, or between two non-working ones, cannot change the answer and takes no lock.
            if ($toWorking !== $fromWorking) {
                $board = $card->board()->withoutGlobalScopes()->first();
                if ($board !== null) {
                    app(WaveCampaignLink::class)->lockWaveOfCampaign($board->campaign_id);
                }
            }

            $fromColumnId = $card->column_id;
            $card->update(['column_id' => $targetColumn->id]);

            BoardCardMovement::query()->create([
                'card_id' => $card->id,
                'from_column_id' => $fromColumnId,
                'to_column_id' => $targetColumn->id,
                'triggered_by' => MovementTrigger::User,
                'triggered_event_key' => null,
                'triggered_by_user_id' => $actor->id,
                'reason' => $reason,
            ]);

            // The second trail (D-9): the audit row records the FACT + actor.
            // `reason` is optional (NOT requiresReason()).
            Audit::log(
                action: AuditAction::BoardCardMovedManually,
                actor: $actor,
                subject: $card,
                reason: $reason,
                metadata: [
                    'from_column_id' => $fromColumnId,
                    'to_column_id' => $targetColumn->id,
                    'assignment_id' => $card->assignment_id,
                ],
            );
        });

        return $card;
    }

    /**
     * PL-3.1 (plan 1.6, P8) — the RULE-DRIVEN move of a wave board's card: no acting `User`, a movement row with
     * `triggered_by = event` and the rule's own event key (`wave_creator.selected`, `wave_creator.reverted`). Called ONLY by
     * the Strategy module's `WaveCreatorBoardRules`, which holds the wave row lock; that is ASSERTED (a transaction must be
     * open) rather than assumed, and the move itself takes no lock. The Q4 acceptance rule is enforced here exactly as in
     * {@see self::move()}: only a creator who has Accepted enters a working column. {@see self::move()} is untouched.
     *
     * No audit row is written here: the caller records the fact (`wave_creator.card_moved`), the movement row records the event.
     */
    public function moveByRule(BoardCard $card, BoardColumn $targetColumn, string $eventKey, ?int $triggeredByUserId = null): BoardCard
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('A rule-driven card move runs inside the wave-locked transaction of its caller.');
        }
        if ($card->column_id === $targetColumn->id) {
            return $card;
        }

        $toWorking = $targetColumn->stage_key !== null && in_array($targetColumn->stage_key, BoardDefaults::waveWorkingStages(), true);
        if ($toWorking && $card->assignment?->status->countsAsSelected() !== true) {
            throw BoardRuleViolation::selectionRequiresAcceptance();
        }

        $fromColumnId = $card->column_id;
        $card->update(['column_id' => $targetColumn->id]);

        BoardCardMovement::query()->create([
            'card_id' => $card->id,
            'from_column_id' => $fromColumnId,
            'to_column_id' => $targetColumn->id,
            'triggered_by' => MovementTrigger::Event,
            'triggered_event_key' => $eventKey,
            'triggered_by_user_id' => $triggeredByUserId,
            'reason' => null,
        ]);

        return $card;
    }
}
