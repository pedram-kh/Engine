<?php

declare(strict_types=1);

namespace App\Modules\Boards\Services;

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Boards\Enums\BoardAutomationActionType;
use App\Modules\Boards\Listeners\CreateBoardCard;
use App\Modules\Boards\Models\Board;
use App\Modules\Boards\Models\BoardCard;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Models\CampaignAssignment;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Provisions the one-per-assignment board card (Sprint 12 Chunk 1, D-5).
 *
 * Idempotency across the two create sites — the invite listener
 * ({@see CreateBoardCard}) and the lazy GET
 * card-heal ({@see BoardService::forCampaign()}) — is backed by the
 * `board_cards.assignment_id` UNIQUE. {@see self::forAssignment()} is race-safe:
 * a concurrent double-create collides on the unique and is caught + re-fetched
 * rather than throwing (the MessageThreadService precedent).
 *
 * The global BelongsToAgency scope is deliberately bypassed (the named,
 * greppable construct per docs/security/tenancy.md §5): card provisioning is
 * idempotent infrastructure keyed on the UNIQUE, `agency_id` is ALWAYS set
 * explicitly from the already-resolved board, and the queued invite listener
 * runs with no ambient tenant context. The caller passes a board it has already
 * ensured (no cross-tenant read).
 */
final class BoardCardService
{
    public function forAssignment(Board $board, CampaignAssignment $assignment): BoardCard
    {
        $columnId = $this->resolveInitialColumnId($board, $assignment->status);

        try {
            return BoardCard::query()
                ->withoutGlobalScope(BelongsToAgencyScope::class)
                ->firstOrCreate(
                    ['assignment_id' => $assignment->id],
                    [
                        'board_id' => $board->id,
                        'agency_id' => $board->agency_id,
                        'column_id' => $columnId,
                        'position' => 0,
                    ],
                );
        } catch (UniqueConstraintViolationException) {
            // A concurrent create won the race — re-fetch the canonical row.
            return BoardCard::query()
                ->withoutGlobalScope(BelongsToAgencyScope::class)
                ->where('assignment_id', $assignment->id)
                ->firstOrFail();
        }
    }

    /**
     * Resolve the column a freshly-provisioned card lands in (§6.1). The card is
     * placed in the target column of the automation whose event REPRESENTS the
     * assignment's current state (so a lazy heal of an advanced assignment lands
     * sensibly), honoring the board's own automation config; falling back to the
     * `assignment.invited` target, then the first column by position.
     *
     * This is purely a VISUALIZATION placement (§4.4 — board state never drives
     * reality). Statuses with no representative automation (declined / rejected /
     * the accepted→producing middle, §3.3) fall through to the first column,
     * where the agency can drag them or the next event re-places them.
     */
    private function resolveInitialColumnId(Board $board, AssignmentStatus $status): int
    {
        $columns = $board->columns()->get(['id', 'position']);

        $candidateKeys = array_values(array_filter([
            $this->representativeEventKey($status),
            AuditAction::AssignmentInvited->value,
        ]));

        foreach ($candidateKeys as $eventKey) {
            $automation = $board->automations()
                ->where('event_key', $eventKey)
                ->where('is_enabled', true)
                ->where('action_type', BoardAutomationActionType::MoveToColumn->value)
                ->whereNotNull('target_column_id')
                ->first();

            if ($automation !== null && $columns->contains('id', $automation->target_column_id)) {
                return (int) $automation->target_column_id;
            }
        }

        $first = $columns->sortBy('position')->first();
        if ($first === null) {
            // A board always has columns by the time a card is provisioned
            // (provisioning seeds them first); this guard satisfies the type.
            throw new \RuntimeException("Board {$board->id} has no columns to place a card in.");
        }

        return (int) $first->id;
    }

    /**
     * Maps an assignment status to the catalogue event key whose automation
     * target is the natural column for that state (§3.2 semantics). Returns null
     * for states with no representative automation (§3.3 — they stay put).
     */
    private function representativeEventKey(AssignmentStatus $status): ?string
    {
        return match ($status) {
            AssignmentStatus::Invited,
            AssignmentStatus::Countered,
            AssignmentStatus::Accepted,
            AssignmentStatus::Contracted,
            AssignmentStatus::Producing => AuditAction::AssignmentInvited->value,
            AssignmentStatus::DraftSubmitted => AuditAction::AssignmentDraftSubmitted->value,
            AssignmentStatus::Approved,
            AssignmentStatus::RevisionRequested => AuditAction::AssignmentDraftApproved->value,
            AssignmentStatus::Posted,
            AssignmentStatus::LiveVerified,
            AssignmentStatus::ManuallyVerified => AuditAction::AssignmentPostedByCreator->value,
            AssignmentStatus::PaymentHeld,
            AssignmentStatus::PaymentReleased => AuditAction::AssignmentPaymentReleased->value,
            AssignmentStatus::Cancelled => AuditAction::AssignmentCancelled->value,
            AssignmentStatus::Declined,
            AssignmentStatus::Rejected => null,
        };
    }
}
