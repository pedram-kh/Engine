<?php

declare(strict_types=1);

use App\Modules\Agencies\Enums\BlacklistType;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Enums\CampaignObjective;
use App\Modules\Campaigns\Enums\CampaignStatus;
use App\Modules\Creators\Enums\RelationshipStatus;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Catalogue tripwires for the Sprint 8 campaign enums (D-9), mirroring the
 * {@see RelationshipStatus} /
 * {@see BlacklistType} discipline. Pin the exact
 * case sets so any add/remove is a deliberate, reviewed change that forces
 * every consumer (the model casts, the state machine, the board event
 * vocabulary, the FE types) to be revisited.
 */
it('CampaignStatus catalogue pins the exact case set', function (): void {
    $expected = ['draft', 'active', 'paused', 'completed', 'cancelled'];

    $actual = array_map(fn (CampaignStatus $case): string => $case->value, CampaignStatus::cases());

    sort($expected);
    sort($actual);

    expect($actual)->toBe($expected, 'CampaignStatus enum drifted from the locked catalogue.');
});

it('CampaignObjective catalogue pins the exact case set', function (): void {
    $expected = ['awareness', 'engagement', 'conversion', 'ugc', 'launch'];

    $actual = array_map(fn (CampaignObjective $case): string => $case->value, CampaignObjective::cases());

    sort($expected);
    sort($actual);

    expect($actual)->toBe($expected, 'CampaignObjective enum drifted from the locked catalogue.');
});

it('AssignmentStatus catalogue pins the exact 17-case set (the full state graph)', function (): void {
    // Sprint 9 Chunk 2 (D-1) added the dedicated `rejected` terminal. The
    // verification-resolution chunk (D-1) adds the non-terminal
    // `manually_verified` — the agency's manual override of a FAILED
    // auto-verification (`posted → manually_verified`). AH-069 (D3) adds the
    // `completed_on_approval` terminal — the finish line for a campaign whose
    // creators do not post the deliverable. Bumping this catalogue from 16 → 17
    // is the deliberate, reviewed enum-add it is meant to force.
    $expected = [
        'invited',
        'declined',
        'countered',
        'accepted',
        'contracted',
        'producing',
        'draft_submitted',
        'revision_requested',
        'approved',
        'rejected',
        'completed_on_approval',
        'posted',
        'live_verified',
        'manually_verified',
        'payment_held',
        'payment_released',
        'cancelled',
    ];

    $actual = array_map(fn (AssignmentStatus $case): string => $case->value, AssignmentStatus::cases());

    sort($expected);
    sort($actual);

    expect($actual)->toBe($expected, 'AssignmentStatus enum drifted from the locked state-machine catalogue.');
});

it('AssignmentStatus terminal states are exactly declined / rejected / completed_on_approval / payment_released / cancelled', function (): void {
    $terminal = array_values(array_filter(
        AssignmentStatus::cases(),
        fn (AssignmentStatus $case): bool => $case->isTerminal(),
    ));

    $terminalValues = array_map(fn (AssignmentStatus $case): string => $case->value, $terminal);

    expect($terminalValues)->toEqualCanonicalizing([
        'declined',
        'rejected',
        'completed_on_approval',
        'payment_released',
        'cancelled',
    ]);
});

it('completed_on_approval is TERMINAL — the assignment is finished, not waiting on a post (AH-069 D3)', function (): void {
    expect(AssignmentStatus::CompletedOnApproval->isTerminal())->toBeTrue();
});

it('manually_verified is NON-terminal (payment follows, like live_verified)', function (): void {
    expect(AssignmentStatus::ManuallyVerified->isTerminal())->toBeFalse();
});

it('AssignmentStatus payment-eligible states are exactly live_verified + manually_verified + completed_on_approval (D-3, AH-069 D4)', function (): void {
    // The dead-end-preventer: a manual override must be payment-eligible like a
    // real auto-verification. Proven NOW — both states satisfy the same
    // predicate the S10 release-gate will consume — without payment being built.
    //
    // AH-069 D4 adds `completed_on_approval` on the same logic: on a campaign
    // that hands off at approval, the approval IS the completion, so refusing to
    // pay it would leave finished work unpaid. ⚠ The consequence Sprint 10
    // inherits, in writing: this is the FIRST payment-eligible state with no
    // `campaign_posted_content` row behind it. A release path that joins posted
    // content to locate the "verified" record will find nothing. Consume the
    // predicate, never the row.
    $eligible = array_values(array_filter(
        AssignmentStatus::cases(),
        fn (AssignmentStatus $case): bool => $case->isPaymentEligible(),
    ));

    $eligibleValues = array_map(fn (AssignmentStatus $case): string => $case->value, $eligible);

    expect($eligibleValues)->toEqualCanonicalizing([
        'live_verified',
        'manually_verified',
        'completed_on_approval',
    ]);

    // The equivalence, asserted directly: all three satisfy the predicate;
    // `posted` (the failed-verification state) and `approved` (the OTHER
    // campaign type's mid-flight state) do not.
    expect(AssignmentStatus::LiveVerified->isPaymentEligible())->toBeTrue()
        ->and(AssignmentStatus::ManuallyVerified->isPaymentEligible())->toBeTrue()
        ->and(AssignmentStatus::CompletedOnApproval->isPaymentEligible())->toBeTrue()
        ->and(AssignmentStatus::Posted->isPaymentEligible())->toBeFalse()
        ->and(AssignmentStatus::Approved->isPaymentEligible())->toBeFalse();
});

it('CampaignStatus values fit the varchar(16) status column', function (): void {
    foreach (CampaignStatus::cases() as $case) {
        expect(strlen($case->value))->toBeLessThanOrEqual(16);
    }
});

it('CampaignObjective values fit the varchar(32) objective column', function (): void {
    foreach (CampaignObjective::cases() as $case) {
        expect(strlen($case->value))->toBeLessThanOrEqual(32);
    }
});

it('AssignmentStatus values fit the varchar(32) status column', function (): void {
    foreach (AssignmentStatus::cases() as $case) {
        expect(strlen($case->value))->toBeLessThanOrEqual(32);
    }
});
