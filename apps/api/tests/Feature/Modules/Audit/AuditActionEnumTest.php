<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use Tests\TestCase;

uses(TestCase::class);

it('AuditAction catalogue lists every Sprint 1-6 auth + user + mfa + brand + invitation + settings + creator + bulk_invite verb', function (): void {
    $expected = [
        // Sprint 1 — auth
        'auth.signup',
        'auth.login.succeeded',
        'auth.login.failed',
        'auth.logout',
        'auth.password.reset_requested',
        'auth.password.reset_completed',
        'auth.password.changed',
        'auth.email.verification_sent',
        'auth.email.verified',
        'auth.account_locked.suspended',
        'auth.account_unlocked',
        // Sprint 1 — MFA
        'mfa.enabled',
        'mfa.confirmed',
        'mfa.disabled',
        'mfa.recovery_codes_regenerated',
        'mfa.recovery_code_consumed',
        'mfa.enrollment_suspended',
        // Sprint 1 — user
        'user.created',
        'user.updated',
        'user.deleted',
        'user.suspended',
        'user.unsuspended',
        // Sprint 2 — brands
        'brand.created',
        'brand.updated',
        'brand.archived',
        'brand.restored',
        // Sprint 2 — invitations
        'invitation.created',
        'invitation.accepted',
        'invitation.expired_on_attempt',
        // Sprint 2 — agency settings
        'agency_settings.updated',
        // Sprint 3 — creator domain
        'creator.created',
        'creator.updated',
        'creator.deleted',
        'creator.wizard.profile_completed',
        'creator.wizard.social_completed',
        'creator.wizard.portfolio_completed',
        'creator.wizard.kyc_initiated',
        'creator.wizard.tax_completed',
        'creator.wizard.payout_initiated',
        'creator.wizard.contract_initiated',
        'creator.submitted',
        'creator.invited',
        'bulk_invite.started',
        'bulk_invite.completed',
        'bulk_invite.failed',
        // Sprint 3 Chunk 4 — magic-link invitation acceptance
        'creator.invitation_accepted',
        // Sprint 3 Chunk 4 — admin per-field edit + approve / reject
        'creator.admin.field_updated',
        'creator.approved',
        'creator.rejected',
        // Sprint 4 Chunk 3 — manual KYC verify (D-c3-3) + resubmit reopen (D-c3-9)
        'creator.kyc.manually_verified',
        'creator.application.reopened',
        // Sprint 3 — auto-emitted by Audited trait on related models
        'creator_tax_profile.created',
        'creator_tax_profile.updated',
        'creator_tax_profile.deleted',
        'creator_payout_method.created',
        'creator_payout_method.updated',
        'creator_payout_method.deleted',
        'agency_creator_relation.created',
        'agency_creator_relation.updated',
        'agency_creator_relation.deleted',
        // Sprint 6 Chunk 2a — redacted notes-edit event (D-2a-5)
        'agency_creator_relation.notes_updated',
        // Sprint 7 — creator blacklisting (D-5)
        'creator.blacklisted',
        'brand_creator_blacklist.created',
        'brand_creator_blacklist.deleted',
        // Sprint 6 Chunk 2b — talent pools CRUD + membership
        'talent_pool.created',
        'talent_pool.updated',
        'talent_pool.archived',
        'talent_pool.restored',
        'talent_pool.creator_added',
        'talent_pool.creator_removed',
        // Sprint 3 Chunk 2 — wizard completion-pairs (status-poll + webhook)
        'creator.wizard.kyc_completed',
        'creator.wizard.contract_completed',
        'creator.wizard.payout_completed',
        'creator.wizard.click_through_accepted',
        // Sprint 3 Chunk 2 — inbound webhook lifecycle
        'integration.webhook.received',
        'integration.webhook.processed',
        'integration.webhook.signature_failed',
        // Sprint 8 Chunk 1 — campaign CRUD
        'campaign.created',
        'campaign.updated',
        // Sprint 8 Chunk 1 — campaign-assignment state-machine transitions
        // (the board's future event vocabulary — matches 10-BOARD-AUTOMATION §2).
        'assignment.invited',
        'assignment.declined',
        'assignment.countered',
        'assignment.accepted',
        'assignment.contracted',
        'assignment.producing',
        'assignment.draft_submitted',
        'assignment.revision_requested',
        'assignment.draft_approved',
        // Sprint 9 Chunk 2 — the agency's dedicated draft-rejection terminal.
        'assignment.draft_rejected',
        'assignment.posted_by_creator',
        'assignment.live_verified',
        'assignment.payment_funded',
        'assignment.payment_released',
        'assignment.cancelled',
        // Sprint 8 Chunk 2 — the agency re-offer after a creator counter (D-7).
        'assignment.re_invited',
        // Verification-resolution chunk — the agency's resolution of a FAILED
        // auto-verification (manual override + the two resubmit movements) +
        // the creator's in-place posted-content edit.
        'assignment.manually_verified',
        'assignment.resubmit_requested',
        'assignment.resubmit_requested_in_place',
        'assignment.posted_content_updated',
        // Sprint 11 (D-7) — the dual-recipient message-notification verbs (no
        // audit row written; they exist for the NotificationType vocabulary tie).
        'message.received_by_creator',
        'message.received_by_agency',
        // Sprint 12 Chunk 1 (D-9) — the manual board-card move verb (audit-only;
        // NOT a NotificationType, so it does not join the one-vocabulary tie).
        'board.card_moved_manually',
        // Sprint 12 Chunk 3 (D-1) — the two time-triggered overdue event keys
        // (no audit row — they ride processEvent, movement-only) + the
        // destructive reset-to-defaults verb (the only one that writes a row).
        // All audit-only — NONE join the NotificationType one-vocabulary tie.
        'assignment.posting_overdue',
        'assignment.draft_overdue',
        'board.reset',
    ];

    $actual = array_map(fn (AuditAction $case): string => $case->value, AuditAction::cases());

    sort($expected);
    sort($actual);

    expect($actual)->toBe($expected, 'AuditAction enum drifted from Sprint 1-3 catalogue.');
});

it('reason-mandatory actions match docs/05-SECURITY-COMPLIANCE.md §3.3', function (): void {
    $required = array_values(array_filter(
        AuditAction::cases(),
        fn (AuditAction $case): bool => $case->requiresReason(),
    ));

    $requiredValues = array_map(fn (AuditAction $a): string => $a->value, $required);

    expect($requiredValues)->toEqualCanonicalizing([
        'auth.account_unlocked',
        'user.deleted',
        'user.suspended',
        'user.unsuspended',
        'assignment.cancelled',
        // Sprint 9 Chunk 2 — reject carries a mandatory reason (the review feedback).
        'assignment.draft_rejected',
        // Verification-resolution chunk — the manual override must answer "why
        // was this paid despite failing verification" (D-4).
        'assignment.manually_verified',
    ]);
});

it('non-destructive actions do not require a reason', function (): void {
    expect(AuditAction::AuthLoginSucceeded->requiresReason())->toBeFalse()
        ->and(AuditAction::AuthLoginFailed->requiresReason())->toBeFalse()
        ->and(AuditAction::UserCreated->requiresReason())->toBeFalse()
        ->and(AuditAction::UserUpdated->requiresReason())->toBeFalse()
        ->and(AuditAction::AuthAccountLockedSuspended->requiresReason())->toBeFalse()
        ->and(AuditAction::MfaEnabled->requiresReason())->toBeFalse()
        ->and(AuditAction::MfaConfirmed->requiresReason())->toBeFalse()
        ->and(AuditAction::MfaDisabled->requiresReason())->toBeFalse();
});

it('flags mfa state-mutating verbs as sensitive credential actions', function (): void {
    $sensitive = array_values(array_filter(
        AuditAction::cases(),
        fn (AuditAction $case): bool => $case->isSensitiveCredentialAction(),
    ));

    $sensitiveValues = array_map(fn (AuditAction $a): string => $a->value, $sensitive);

    expect($sensitiveValues)->toEqualCanonicalizing([
        'mfa.enabled',
        'mfa.confirmed',
        'mfa.disabled',
        'mfa.recovery_codes_regenerated',
        'mfa.recovery_code_consumed',
        'auth.password.changed',
        'auth.password.reset_completed',
    ]);
});

it('non-credential actions are not flagged sensitive', function (): void {
    expect(AuditAction::AuthLoginSucceeded->isSensitiveCredentialAction())->toBeFalse()
        ->and(AuditAction::AuthLogout->isSensitiveCredentialAction())->toBeFalse()
        ->and(AuditAction::UserCreated->isSensitiveCredentialAction())->toBeFalse()
        ->and(AuditAction::MfaEnrollmentSuspended->isSensitiveCredentialAction())->toBeFalse();
});
