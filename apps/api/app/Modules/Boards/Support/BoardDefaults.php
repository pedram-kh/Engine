<?php

declare(strict_types=1);

namespace App\Modules\Boards\Support;

use App\Modules\Audit\Enums\AuditAction;

/**
 * The single source of truth for "what a default board is" (Sprint 12 Chunk 1,
 * D-3). Per docs/10-BOARD-AUTOMATION.md §3.1 (columns) + §3.2 (automations).
 *
 * Column color tokens use the §3.1 spelling (`status-*`); each maps 1:1 to the
 * `boardStatus` palette in packages/design-tokens (verified at plan-pause —
 * Seam 1). Automation event keys are AuditAction verb values (the §2 catalogue),
 * so each default binds to a live, dispatched event (Seam 4).
 *
 * Note (D-11): the `payment_released → Paid` default is wired but INERT in P1 —
 * the state machine's releasePayment() throws escrowUnavailable() until Sprint
 * 10, so the event never fires. No §3.2 default references a time-triggered
 * overdue key (D-12), so nothing is seeded that dangles.
 */
final class BoardDefaults
{
    /**
     * The 7 default columns, in order (§3.1). `success`/`failure` mark the
     * terminal columns (§7.5). The failure terminal is "Cancelled / Rejected" —
     * it hosts BOTH dead-end verbs (cancel and draft-reject share one column).
     *
     * @return list<array{name: string, color_token: string, is_terminal_success: bool, is_terminal_failure: bool}>
     */
    public static function columns(): array
    {
        return [
            self::column('To Define', 'status-todefine'),
            self::column('Invited', 'status-progress'),
            self::column('In Review', 'status-review'),
            self::column('Approved', 'status-aligned'),
            self::column('Posted', 'status-posted'),
            self::column('Paid', 'status-paid', success: true),
            self::column('Cancelled / Rejected', 'status-blocked', failure: true),
        ];
    }

    /**
     * The 10 default automations (§3.2 + draft-reject): event key → target
     * column NAME. The provisioner resolves the name to the seeded column's id.
     *
     * @return list<array{event_key: string, target_column_name: string}>
     */
    public static function automations(): array
    {
        return [
            self::automation(AuditAction::AssignmentInvited, 'Invited'),
            self::automation(AuditAction::AssignmentDraftSubmitted, 'In Review'),
            self::automation(AuditAction::AssignmentDraftApproved, 'Approved'),
            self::automation(AuditAction::AssignmentPostedByCreator, 'Posted'),
            self::automation(AuditAction::AssignmentLiveVerified, 'Posted'),
            self::automation(AuditAction::AssignmentManuallyVerified, 'Posted'),
            self::automation(AuditAction::AssignmentResubmitRequested, 'Approved'),
            // INERT until Sprint 10 (D-11) — escrow is gated, so the event never fires.
            self::automation(AuditAction::AssignmentPaymentReleased, 'Paid'),
            self::automation(AuditAction::AssignmentCancelled, 'Cancelled / Rejected'),
            // Draft rejection is the terminal review outcome — the card lands in
            // the same failure column as a cancel.
            self::automation(AuditAction::AssignmentDraftRejected, 'Cancelled / Rejected'),
        ];
    }

    /**
     * The POSTING FAMILY of automation verbs (AH-069, D6) — the transitions that
     * can only ever fire on a campaign whose creators post the deliverable.
     *
     * Used to decide, at RENDER time, which column an OFF campaign's board should
     * not show. Deriving the hidden column from the automations that target it —
     * rather than from the name "Posted" — is what makes the filter survive a
     * rename, and what keeps it from ever hiding a column that something else
     * also routes to. Nothing is deleted: the column row, its automations and any
     * card on it all stay exactly where they are.
     *
     * @return list<string>
     */
    public static function postingFamilyEventKeys(): array
    {
        return [
            AuditAction::AssignmentPostedByCreator->value,
            AuditAction::AssignmentLiveVerified->value,
            AuditAction::AssignmentManuallyVerified->value,
        ];
    }

    /**
     * The design-system status palette (§1.2 — "column colors chosen from the
     * design system status palette"). The allow-set the column requests
     * validate against; each maps 1:1 to a `boardStatus` token in
     * packages/design-tokens (Seam 1).
     *
     * @return list<string>
     */
    public static function colorTokens(): array
    {
        return array_map(
            static fn (array $column): string => $column['color_token'],
            self::columns(),
        );
    }

    /**
     * PL-1.2 — the eight DATA columns of a for-a-wave board, in order. The ninth, Applied, is the existing
     * pseudo-column of pending applications and is not a row (Q1). `stage_key` is what the link lock and the
     * acceptance rule read, so neither depends on a name or a position. Colours are the existing palette only.
     *
     * @return list<array{name: string, color_token: string, is_terminal_success: bool, is_terminal_failure: bool, stage_key: string}>
     */
    public static function waveColumns(): array
    {
        return [
            self::waveColumn('Invited', 'status-progress', 'invited'),
            self::waveColumn('Selected', 'status-todefine', 'selected'),
            self::waveColumn('Filming', 'status-posted', 'filming'),
            self::waveColumn('Submitted / in review', 'status-review', 'submitted'),
            self::waveColumn('In edits', 'status-progress', 'in_edits'),
            self::waveColumn('Client review', 'status-review', 'client_review'),
            self::waveColumn('Approved', 'status-aligned', 'approved'),
            self::waveColumn('Dropped', 'status-blocked', 'dropped', failure: true),
        ];
    }

    /**
     * PL-1.2 — TWO automations only: an invite creates the card in Invited, a cancel lands it in Dropped. Every
     * other move on a wave board is by hand. Accepted and Declined have no automation, so they stay in Invited.
     *
     * @return list<array{event_key: string, target_column_name: string}>
     */
    public static function waveAutomations(): array
    {
        return [
            self::automation(AuditAction::AssignmentInvited, 'Invited'),
            self::automation(AuditAction::AssignmentCancelled, 'Dropped'),
        ];
    }

    /**
     * PL-1.2 — the stage keys a creator must have Accepted to enter, and the set that locks the campaign's wave
     * link while at least one card sits in it (Q3, Q4).
     *
     * @return list<string>
     */
    public static function waveWorkingStages(): array
    {
        return ['selected', 'filming', 'submitted', 'in_edits', 'client_review', 'approved'];
    }

    /**
     * @return array{name: string, color_token: string, is_terminal_success: bool, is_terminal_failure: bool, stage_key: string}
     */
    private static function waveColumn(string $name, string $colorToken, string $stageKey, bool $failure = false): array
    {
        return [...self::column($name, $colorToken, failure: $failure), 'stage_key' => $stageKey];
    }

    /**
     * @return array{name: string, color_token: string, is_terminal_success: bool, is_terminal_failure: bool}
     */
    private static function column(string $name, string $colorToken, bool $success = false, bool $failure = false): array
    {
        return [
            'name' => $name,
            'color_token' => $colorToken,
            'is_terminal_success' => $success,
            'is_terminal_failure' => $failure,
        ];
    }

    /**
     * @return array{event_key: string, target_column_name: string}
     */
    private static function automation(AuditAction $action, string $targetColumnName): array
    {
        return [
            'event_key' => $action->value,
            'target_column_name' => $targetColumnName,
        ];
    }
}
