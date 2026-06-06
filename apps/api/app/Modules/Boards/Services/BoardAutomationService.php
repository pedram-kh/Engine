<?php

declare(strict_types=1);

namespace App\Modules\Boards\Services;

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Boards\Enums\BoardAutomationActionType;
use App\Modules\Boards\Enums\MovementTrigger;
use App\Modules\Boards\Models\BoardAutomation;
use App\Modules\Boards\Models\BoardCardMovement;
use App\Modules\Campaigns\Models\CampaignAssignment;
use Illuminate\Support\Facades\DB;

/**
 * Moves a board card in response to an assignment event (Sprint 12 Chunk 1,
 * D-6/D-7). Per docs/10-BOARD-AUTOMATION.md §5.2.
 *
 * Idempotent + no-ops safely (§14.2 + D-7): the service no-ops if the board
 * doesn't exist, the automation is absent/disabled/non-move, the condition
 * fails, the card doesn't yet exist (the belt-and-suspenders against a
 * registration-order slip — D-7), or the card is already in the target column
 * (no double-fire). It reads `assignment.campaign.board` INDEPENDENTLY and
 * writes ONLY `board_cards` + `board_card_movements` — it touches no state the
 * other AssignmentTransitioned consumers touch (no ordering dependency with
 * SendAssignmentNotifications / WriteSystemMessage / CreateMessageThread).
 *
 * Tenancy: reads bypass the BelongsToAgency scope (the named construct per
 * docs/security/tenancy.md §5) — the listener runs in-request with a context,
 * but the bypass keeps the lookups context-independent and side-effect free.
 *
 * @param  array<string, mixed>  $metadata
 */
final class BoardAutomationService
{
    public function processEvent(
        int $assignmentId,
        string $eventKey,
        array $metadata,
        ?int $triggeredByUserId,
    ): void {
        $assignment = CampaignAssignment::query()
            ->withoutGlobalScope(BelongsToAgencyScope::class)
            ->with(['campaign.board', 'boardCard'])
            ->find($assignmentId);

        $board = $assignment?->campaign?->board;
        if ($assignment === null || $board === null) {
            return;
        }

        $automation = $board->automations()
            ->where('event_key', $eventKey)
            ->where('is_enabled', true)
            ->first();

        if ($automation === null
            || $automation->action_type !== BoardAutomationActionType::MoveToColumn
            || $automation->target_column_id === null) {
            return;
        }

        if (! $this->evaluateCondition($automation, $assignment)) {
            return;
        }

        $card = $assignment->boardCard;

        // No-op when no card yet (D-7 belt + suspenders) or already in the
        // target column (§14.2 — no duplicate movement).
        if ($card === null || $card->column_id === $automation->target_column_id) {
            return;
        }

        DB::transaction(function () use ($card, $automation, $eventKey, $triggeredByUserId): void {
            $fromColumnId = $card->column_id;
            $card->update(['column_id' => $automation->target_column_id]);

            BoardCardMovement::query()->create([
                'card_id' => $card->id,
                'from_column_id' => $fromColumnId,
                'to_column_id' => $automation->target_column_id,
                'triggered_by' => MovementTrigger::Event,
                'triggered_event_key' => $eventKey,
                'triggered_by_user_id' => $triggeredByUserId,
                'reason' => null,
            ]);
        });
    }

    /**
     * Phase-1 condition evaluation (§5.3). The condition catalogue
     * (brand_auto_approve, amount thresholds, category filters) is not built
     * this chunk — no default seeds a condition — so an empty/absent condition
     * passes. The seam exists for the future enumerated set.
     */
    private function evaluateCondition(BoardAutomation $automation, CampaignAssignment $assignment): bool
    {
        $condition = $automation->condition;

        return $condition === null || $condition === [];
    }
}
