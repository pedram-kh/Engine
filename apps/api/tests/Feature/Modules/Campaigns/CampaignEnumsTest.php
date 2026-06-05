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

it('AssignmentStatus catalogue pins the exact 14-case set (the full state graph)', function (): void {
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
        'posted',
        'live_verified',
        'payment_held',
        'payment_released',
        'cancelled',
    ];

    $actual = array_map(fn (AssignmentStatus $case): string => $case->value, AssignmentStatus::cases());

    sort($expected);
    sort($actual);

    expect($actual)->toBe($expected, 'AssignmentStatus enum drifted from the locked state-machine catalogue.');
});

it('AssignmentStatus terminal states are exactly declined / payment_released / cancelled', function (): void {
    $terminal = array_values(array_filter(
        AssignmentStatus::cases(),
        fn (AssignmentStatus $case): bool => $case->isTerminal(),
    ));

    $terminalValues = array_map(fn (AssignmentStatus $case): string => $case->value, $terminal);

    expect($terminalValues)->toEqualCanonicalizing(['declined', 'payment_released', 'cancelled']);
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
