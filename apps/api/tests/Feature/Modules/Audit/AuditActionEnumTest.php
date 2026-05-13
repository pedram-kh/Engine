<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use Tests\TestCase;

uses(TestCase::class);

it('AuditAction catalogue lists every Sprint 1+2 auth + user + mfa + brand + invitation + settings verb', function (): void {
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
    ];

    $actual = array_map(fn (AuditAction $case): string => $case->value, AuditAction::cases());

    sort($expected);
    sort($actual);

    expect($actual)->toBe($expected, 'AuditAction enum drifted from Sprint 1+2 catalogue.');
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
