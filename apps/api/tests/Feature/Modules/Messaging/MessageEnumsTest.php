<?php

declare(strict_types=1);

use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Messaging\Enums\MessageKind;
use App\Modules\Messaging\Enums\MessageSenderRole;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Catalogue tripwires for the Sprint 11 messaging enums (D-15), mirroring the
 * {@see AssignmentStatus} / NotificationType
 * discipline. Pin the exact case sets + the varchar(16) fit so any add/remove
 * is a deliberate, reviewed change that forces every consumer (the model casts,
 * the sender-role/kind validation, the FE types) to be revisited.
 */
it('MessageSenderRole catalogue pins the exact case set', function (): void {
    $expected = ['creator', 'agency_user', 'brand_user', 'system', 'admin'];

    $actual = array_map(fn (MessageSenderRole $case): string => $case->value, MessageSenderRole::cases());

    sort($expected);
    sort($actual);

    expect($actual)->toBe($expected, 'MessageSenderRole enum drifted from the locked catalogue.');
});

it('MessageKind catalogue pins the exact case set', function (): void {
    $expected = ['text', 'system', 'attachment_only'];

    $actual = array_map(fn (MessageKind $case): string => $case->value, MessageKind::cases());

    sort($expected);
    sort($actual);

    expect($actual)->toBe($expected, 'MessageKind enum drifted from the locked catalogue.');
});

it('MessageSenderRole values fit the varchar(16) sender_role column', function (): void {
    foreach (MessageSenderRole::cases() as $case) {
        expect(strlen($case->value))->toBeLessThanOrEqual(16);
    }
});

it('MessageKind values fit the varchar(16) kind column', function (): void {
    // `attachment_only` is 15 chars — the longest value, fitting exactly.
    foreach (MessageKind::cases() as $case) {
        expect(strlen($case->value))->toBeLessThanOrEqual(16);
    }
});
