<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use Tests\TestCase;

uses(TestCase::class);

it('AuditAction catalogue lists every Sprint 1 auth + user verb', function (): void {
    $expected = [
        'auth.signup',
        'auth.login.succeeded',
        'auth.login.failed',
        'auth.logout',
        'auth.password.reset_requested',
        'auth.password.reset_completed',
        'auth.password.changed',
        'auth.email.verification_sent',
        'auth.email.verified',
        'auth.two_factor.enabled',
        'auth.two_factor.disabled',
        'auth.two_factor.challenge_succeeded',
        'auth.two_factor.challenge_failed',
        'auth.account_locked',
        'auth.account_unlocked',
        'user.created',
        'user.updated',
        'user.deleted',
        'user.suspended',
        'user.unsuspended',
    ];

    $actual = array_map(fn (AuditAction $case): string => $case->value, AuditAction::cases());

    sort($expected);
    sort($actual);

    expect($actual)->toBe($expected, 'AuditAction enum drifted from chunk 2 catalogue.');
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
        ->and(AuditAction::AuthAccountLocked->requiresReason())->toBeFalse();
});
