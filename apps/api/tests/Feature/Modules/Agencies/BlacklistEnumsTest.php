<?php

declare(strict_types=1);

use App\Modules\Agencies\Enums\BlacklistScope;
use App\Modules\Agencies\Enums\BlacklistType;
use App\Modules\Creators\Enums\RelationshipStatus;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Catalogue tripwires for the Sprint 7 blacklist enums (D-6), mirroring the
 * {@see RelationshipStatus} discipline. Pin the
 * exact case sets so any add/remove is a deliberate, reviewed change that
 * forces every consumer (the relation cast, the discovery exclusion, the
 * request gate, the scope-aware KPI counts) to be revisited.
 */
it('BlacklistScope catalogue pins the exact case set (agency / brand)', function (): void {
    $expected = ['agency', 'brand'];

    $actual = array_map(fn (BlacklistScope $case): string => $case->value, BlacklistScope::cases());

    sort($expected);
    sort($actual);

    expect($actual)->toBe($expected, 'BlacklistScope enum drifted from the locked catalogue.');
});

it('BlacklistType catalogue pins the exact case set (hard / soft)', function (): void {
    $expected = ['hard', 'soft'];

    $actual = array_map(fn (BlacklistType $case): string => $case->value, BlacklistType::cases());

    sort($expected);
    sort($actual);

    expect($actual)->toBe($expected, 'BlacklistType enum drifted from the locked catalogue.');
});

it('BlacklistType values fit the varchar(8) blacklist_type column', function (): void {
    foreach (BlacklistType::cases() as $case) {
        expect(strlen($case->value))->toBeLessThanOrEqual(8);
    }
});

it('BlacklistScope values fit the varchar(8) blacklist_scope column', function (): void {
    foreach (BlacklistScope::cases() as $case) {
        expect(strlen($case->value))->toBeLessThanOrEqual(8);
    }
});
