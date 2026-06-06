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
     * terminal columns (§7.5).
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
            self::column('Cancelled', 'status-blocked', failure: true),
        ];
    }

    /**
     * The 9 default automations (§3.2): event key → target column NAME. The
     * provisioner resolves the name to the seeded column's id.
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
            self::automation(AuditAction::AssignmentCancelled, 'Cancelled'),
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
