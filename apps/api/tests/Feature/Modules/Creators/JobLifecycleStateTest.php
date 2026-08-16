<?php

declare(strict_types=1);

use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Creators\Enums\JobLifecycleState;
use Tests\Feature\Modules\Campaigns\CampaignEnumsTest;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Catalogue tripwires for D5's coarse lifecycle reflection (AH-059), on the
 * {@see CampaignEnumsTest} discipline.
 *
 * Two layers guard the same invariant and BOTH are required. The mapping's
 * `match` has no `default` arm, so a 17th `AssignmentStatus` case is a PHPStan
 * level-max failure — a BUILD break, which is what the kickoff asked for. These
 * tests are the second layer: they fail loudly on the same change with a message
 * that says what to do about it, and they pin the family membership itself,
 * which the type system cannot see.
 */
it('maps every single AssignmentStatus case — the exhaustiveness pin', function (): void {
    // The whole point: iterate the SOURCE enum, not a hand-listed set. A new
    // case reaches `fromAssignmentStatus()` and either maps or throws — there is
    // no third outcome, because there is no `default` arm to absorb it.
    foreach (AssignmentStatus::cases() as $case) {
        $state = JobLifecycleState::fromAssignmentStatus($case);

        expect($state)->toBeInstanceOf(
            JobLifecycleState::class,
            "AssignmentStatus::{$case->name} has no JobLifecycleState family. Add it to "
            .'JobLifecycleState::fromAssignmentStatus() deliberately — do not add a default arm.',
        );
    }
});

it('partitions the 16 cases into three DISJOINT and COMPLETE families', function (): void {
    $families = [
        JobLifecycleState::InProgress->value => [],
        JobLifecycleState::Completed->value => [],
        JobLifecycleState::Ended->value => [],
    ];

    foreach (AssignmentStatus::cases() as $case) {
        $families[JobLifecycleState::fromAssignmentStatus($case)->value][] = $case->value;
    }

    // COMPLETE: every case landed somewhere, and nothing was counted twice.
    $mapped = array_merge(...array_values($families));

    expect($mapped)->toHaveCount(count(AssignmentStatus::cases()))
        ->and(array_unique($mapped))->toHaveCount(count(AssignmentStatus::cases()));

    // DISJOINT + the exact membership, ratified at plan-pause (Q3). 8 / 6 / 3
    // since AH-069 D4 landed the 17th case in Completed.
    expect($families[JobLifecycleState::InProgress->value])->toEqualCanonicalizing([
        'invited',
        'countered',
        'accepted',
        'contracted',
        'producing',
        'draft_submitted',
        'revision_requested',
        'approved',
    ])
        ->and($families[JobLifecycleState::Completed->value])->toEqualCanonicalizing([
            // AH-069 D4 — the deliberate assignment the exhaustiveness pin
            // forced. `completed_on_approval` is Completed even though its
            // SOURCE state `approved` is In progress: on a campaign that hands
            // off at approval there is no posting step left to wait for, so this
            // is the one place "Completed" after an approval is not the
            // over-promise the `Approved` docblock warns about.
            'completed_on_approval',
            'posted',
            'live_verified',
            'manually_verified',
            'payment_held',
            'payment_released',
        ])
        ->and($families[JobLifecycleState::Ended->value])->toEqualCanonicalizing([
            'declined',
            'rejected',
            'cancelled',
        ]);
});

it('pins the three state values the SPA and api-client mirror', function (): void {
    $actual = array_map(fn (JobLifecycleState $case): string => $case->value, JobLifecycleState::cases());

    expect($actual)->toBe(['in_progress', 'completed', 'ended']);
});

it('does NOT reuse isTerminal() — a fully-paid engagement is Completed, never Ended', function (): void {
    // The trap this test exists for: `isTerminal()` returns TRUE for
    // `payment_released`, so the nearest-looking helper would render a creator's
    // finished, PAID job as "Ended". If someone ever "simplifies" the mapping to
    // `$status->isTerminal() ? Ended : InProgress`, this is the red.
    expect(AssignmentStatus::PaymentReleased->isTerminal())->toBeTrue()
        ->and(JobLifecycleState::fromAssignmentStatus(AssignmentStatus::PaymentReleased))
        ->toBe(JobLifecycleState::Completed);

    // The three genuinely-ended states are a STRICT SUBSET of the terminal set.
    $terminal = array_map(
        fn (AssignmentStatus $c): string => $c->value,
        array_filter(AssignmentStatus::cases(), fn (AssignmentStatus $c): bool => $c->isTerminal()),
    );

    $ended = array_map(
        fn (AssignmentStatus $c): string => $c->value,
        array_filter(
            AssignmentStatus::cases(),
            fn (AssignmentStatus $c): bool => JobLifecycleState::fromAssignmentStatus($c) === JobLifecycleState::Ended,
        ),
    );

    // Two terminal states are deliberately NOT Ended, and for the same reason:
    // both are successful ends. `payment_released` is the paid finish; AH-069's
    // `completed_on_approval` is the delivered-and-handed-off finish. Rendering
    // either as "Ended" would tell a creator their finished work fell through.
    expect(array_diff($ended, $terminal))->toBe([], 'every Ended state must also be terminal')
        ->and(array_diff($terminal, $ended))->toEqualCanonicalizing([
            'payment_released',
            'completed_on_approval',
        ]);

    expect(JobLifecycleState::fromAssignmentStatus(AssignmentStatus::CompletedOnApproval))
        ->toBe(JobLifecycleState::Completed);
});

it('reflects the invitation itself as In progress — the D1 branch depends on it', function (): void {
    // D1's fix is branch ordering over this field: the reflection branch renders
    // BEFORE the rejected branch. That only kills the contradiction if a fresh
    // invitation maps to a state the surfaces will render, so this is the pin
    // under "Not selected must never sit beside a live invitation".
    expect(JobLifecycleState::fromAssignmentStatus(AssignmentStatus::Invited))
        ->toBe(JobLifecycleState::InProgress);
});

it('resolves a raw subquery string, and returns null for the absent case', function (): void {
    expect(JobLifecycleState::tryFromAssignmentStatusValue('invited'))
        ->toBe(JobLifecycleState::InProgress)
        ->and(JobLifecycleState::tryFromAssignmentStatusValue('cancelled'))
        ->toBe(JobLifecycleState::Ended)
        // No assignment for the pair — the subquery selected nothing.
        ->and(JobLifecycleState::tryFromAssignmentStatusValue(null))->toBeNull()
        ->and(JobLifecycleState::tryFromAssignmentStatusValue(''))->toBeNull()
        // Not a status this platform writes. Null rather than a wrong label.
        ->and(JobLifecycleState::tryFromAssignmentStatusValue('not_a_status'))->toBeNull();
});

it('every state value fits the api-client union and carries no separator drift', function (): void {
    foreach (JobLifecycleState::cases() as $case) {
        expect($case->value)->toMatch('/^[a-z]+(_[a-z]+)*$/');
    }
});
