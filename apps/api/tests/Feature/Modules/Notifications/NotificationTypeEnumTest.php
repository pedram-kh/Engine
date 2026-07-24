<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Notifications\Enums\NotificationType;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Catalogue tripwire (S11.0 Chunk 1, D-5) — the AuditActionEnumTest /
 * CampaignEnumsTest precedent. NotificationType is a CURATED subset of the
 * AuditAction vocabulary; adding or removing a case must be a deliberate edit
 * that updates this hardcoded list, never an accidental drift.
 */
it('NotificationType catalogue lists exactly the curated membership', function (): void {
    $expected = [
        // Assignment lifecycle (creator + agency facing).
        'assignment.invited',
        'assignment.declined',
        'assignment.countered',
        'assignment.accepted',
        'assignment.contracted',
        'assignment.draft_submitted',
        'assignment.revision_requested',
        'assignment.draft_approved',
        'assignment.draft_rejected',
        'assignment.manually_verified',
        'assignment.cancelled',
        // Forward payment verbs — deferred-S10 escrow alerts drop-in.
        'assignment.payment_funded',
        'assignment.payment_released',
        // Creator lifecycle (S11.0 Chunk 2) — admin approve/reject in-app.
        'creator.approved',
        'creator.rejected',
        // Messaging (Sprint 11, D-7) — the dual-recipient new-message types.
        'message.received_by_creator',
        'message.received_by_agency',
        // Relationship messaging (AH-010) — the dual-recipient 1:1 DM types.
        'message.relationship_received_by_creator',
        'message.relationship_received_by_agency',
        // AH-051 (D-7) — admin-initiated relation events. Door 2 direct-connect
        // notifies the creator; disconnect notifies both parties (one type).
        'agency_creator_relation.admin_connected',
        'agency_creator_relation.disconnected',
    ];

    $actual = array_map(fn (NotificationType $case): string => $case->value, NotificationType::cases());

    sort($expected);
    sort($actual);

    expect($actual)->toBe($expected, 'NotificationType enum drifted from the curated catalogue.');
});

it('every NotificationType value is a live AuditAction value (one-vocabulary discipline)', function (): void {
    foreach (NotificationType::cases() as $case) {
        expect(AuditAction::tryFrom($case->value))
            ->not->toBeNull("NotificationType::{$case->name} ({$case->value}) is not a valid AuditAction.")
            ->and($case->auditAction())->toBe(AuditAction::from($case->value));
    }
});

it('includes the forward payment verbs so deferred-S10 alerts are drop-in', function (): void {
    expect(NotificationType::tryFrom('assignment.payment_funded'))->toBe(NotificationType::AssignmentPaymentFunded)
        ->and(NotificationType::tryFrom('assignment.payment_released'))->toBe(NotificationType::AssignmentPaymentReleased);
});
