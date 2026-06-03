<?php

declare(strict_types=1);

use App\Modules\Creators\Enums\RelationshipStatus;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Catalogue tripwire for {@see RelationshipStatus} (Sprint 6.6b, D-4).
 *
 * Until 6.6b there was NO enum-catalogue test for RelationshipStatus (unlike
 * AuditActionEnumTest), so the enum had no guard — a future missed consumer of
 * a newly-added status (the 21-consumer ripple) would not be caught. This pins
 * the exact case set so any add/remove is a deliberate, reviewed change that
 * forces the consumer list to be revisited. Mirrors AuditActionEnumTest.
 */
it('RelationshipStatus catalogue pins the exact case set', function (): void {
    $expected = [
        'roster',
        'external',
        'prospect',
        // Sprint 6.6b — the two-sided discovery connection lifecycle (D-1).
        'pending_request',
        'declined',
    ];

    $actual = array_map(fn (RelationshipStatus $case): string => $case->value, RelationshipStatus::cases());

    sort($expected);
    sort($actual);

    expect($actual)->toBe($expected, 'RelationshipStatus enum drifted from the locked catalogue.');
});

it('exposes the two-sided lifecycle values added in Sprint 6.6b', function (): void {
    expect(RelationshipStatus::PendingRequest->value)->toBe('pending_request')
        ->and(RelationshipStatus::Declined->value)->toBe('declined');
});
