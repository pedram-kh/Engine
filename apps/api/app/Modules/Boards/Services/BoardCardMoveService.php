<?php

declare(strict_types=1);

namespace App\Modules\Boards\Services;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Boards\Enums\MovementTrigger;
use App\Modules\Boards\Models\BoardCard;
use App\Modules\Boards\Models\BoardCardMovement;
use App\Modules\Boards\Models\BoardColumn;
use App\Modules\Identity\Models\User;
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

        DB::transaction(function () use ($card, $targetColumn, $actor, $reason): void {
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
}
