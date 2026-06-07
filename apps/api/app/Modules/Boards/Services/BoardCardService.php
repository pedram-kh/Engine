<?php

declare(strict_types=1);

namespace App\Modules\Boards\Services;

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Boards\Enums\BoardAutomationActionType;
use App\Modules\Boards\Listeners\CreateBoardCard;
use App\Modules\Boards\Models\Board;
use App\Modules\Boards\Models\BoardAutomation;
use App\Modules\Boards\Models\BoardCard;
use App\Modules\Boards\Models\BoardColumn;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Models\CampaignAssignment;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;

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
     * The §6.1 placement primitive, parameterized on EXPLICIT column +
     * automation collections (Sprint 12 Chunk 3, D-7/Q2). Extracted from
     * {@see self::resolveInitialColumnId()} so the destructive reset
     * ({@see BoardResetService}) can resolve placement against the FRESH default
     * set mid-transaction, when the board's live `columns()`/`automations()`
     * still hold the about-to-be-deleted custom set (passing the live relations
     * would resolve cards onto columns that the reset is about to delete →
     * RESTRICT violation + orphans). The Ch1 heal path delegates here unchanged.
     *
     * Resolves to the target column of the automation whose event REPRESENTS the
     * assignment's current state, honoring the supplied automation config;
     * falling back to the `assignment.invited` target, then the first column by
     * position. Purely a VISUALIZATION placement (§4.4 — board state never drives
     * reality). Statuses with no representative automation (declined / rejected /
     * the accepted→producing middle, §3.3) fall through to the first column.
     *
     * @param  Collection<int, BoardColumn>  $columns
     * @param  Collection<int, BoardAutomation>  $automations
     */
    public function resolveColumnForState(Collection $columns, Collection $automations, AssignmentStatus $status): int
    {
        $candidateKeys = array_values(array_filter([
            $this->representativeEventKey($status),
            AuditAction::AssignmentInvited->value,
        ]));

        foreach ($candidateKeys as $eventKey) {
            $automation = $automations->first(
                fn (BoardAutomation $automation): bool => $automation->event_key === $eventKey
                    && $automation->is_enabled === true
                    && $automation->action_type === BoardAutomationActionType::MoveToColumn
                    && $automation->target_column_id !== null,
            );

            if ($automation !== null && $columns->contains('id', $automation->target_column_id)) {
                return (int) $automation->target_column_id;
            }
        }

        $first = $columns->sortBy('position')->first();
        if ($first === null) {
            // A board always has columns by the time a card is placed
            // (provisioning + reset seed them first); this guard satisfies the type.
            throw new \RuntimeException('Cannot place a card: the board has no columns.');
        }

        return (int) $first->id;
    }

    /**
     * Resolve the column a freshly-provisioned card lands in (§6.1), reading the
     * board's LIVE columns + automations. The Ch1 heal path — behavior unchanged
     * by the Chunk 3 extraction (it delegates to {@see self::resolveColumnForState()}).
     */
    private function resolveInitialColumnId(Board $board, AssignmentStatus $status): int
    {
        return $this->resolveColumnForState(
            $board->columns()->get(),
            $board->automations()->get(),
            $status,
        );
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
