<?php

declare(strict_types=1);

use App\Modules\Campaigns\Enums\AssignmentStatus;

/**
 * AH-120 A1 — the every-case classification. Each AssignmentStatus sits in
 * EXACTLY ONE of: selected (blocks while the rule holds), pre-selection
 * (never blocks), ends-immediately (declined, rejected, cancelled). A status
 * added later fails here until it is placed, instead of silently blocking, or
 * silently not.
 */
it('places every AssignmentStatus case in exactly one exclusivity class', function (): void {
    foreach (AssignmentStatus::cases() as $case) {
        $classes = array_filter([
            'selected' => $case->countsAsSelected(),
            'pre_selection' => $case->isPreSelection(),
            'ends_immediately' => $case->endsExclusivityImmediately(),
        ]);

        expect($classes)->toHaveCount(1, "{$case->value} must sit in exactly one class, got: ".implode(',', array_keys($classes)));
    }
});

it('pins the three classes to the ruled lists', function (): void {
    $names = static fn (callable $pred): array => array_map(
        static fn (AssignmentStatus $s): string => $s->value,
        array_values(array_filter(AssignmentStatus::cases(), $pred)),
    );

    expect($names(fn (AssignmentStatus $s): bool => $s->countsAsSelected()))->toEqual([
        'accepted', 'contracted', 'producing', 'draft_submitted', 'revision_requested', 'approved',
        'completed_on_approval', 'posted', 'live_verified', 'manually_verified', 'payment_held', 'payment_released',
    ])
        ->and($names(fn (AssignmentStatus $s): bool => $s->isPreSelection()))->toEqual(['invited', 'countered'])
        ->and($names(fn (AssignmentStatus $s): bool => $s->endsExclusivityImmediately()))->toEqual(['declined', 'rejected', 'cancelled']);
});

it('classes live_verified, manually_verified, payment_held, payment_released and completed_on_approval as completed, and only selected states', function (): void {
    $completed = array_values(array_filter(AssignmentStatus::cases(), fn (AssignmentStatus $s): bool => $s->isCompletedForExclusivity()));

    expect(array_map(static fn (AssignmentStatus $s): string => $s->value, $completed))->toEqual([
        'completed_on_approval', 'live_verified', 'manually_verified', 'payment_held', 'payment_released',
    ]);

    foreach ($completed as $status) {
        expect($status->countsAsSelected())->toBeTrue();
    }

    // revision_requested counts as ACTIVE (Q1): selected, not completed.
    expect(AssignmentStatus::RevisionRequested->countsAsSelected())->toBeTrue()
        ->and(AssignmentStatus::RevisionRequested->isCompletedForExclusivity())->toBeFalse();
});
