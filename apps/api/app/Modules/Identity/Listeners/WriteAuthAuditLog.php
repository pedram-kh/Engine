<?php

declare(strict_types=1);

namespace App\Modules\Identity\Listeners;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Events\AccountLocked;
use App\Modules\Identity\Events\EmailVerificationSent;
use App\Modules\Identity\Events\EmailVerified;
use App\Modules\Identity\Events\LoginFailed;
use App\Modules\Identity\Events\PasswordResetCompleted;
use App\Modules\Identity\Events\PasswordResetRequested;
use App\Modules\Identity\Events\TwoFactorConfirmed;
use App\Modules\Identity\Events\TwoFactorDisabled;
use App\Modules\Identity\Events\TwoFactorEnabled;
use App\Modules\Identity\Events\TwoFactorRecoveryCodeConsumed;
use App\Modules\Identity\Events\TwoFactorRecoveryCodesRegenerated;
use App\Modules\Identity\Events\UserLoggedIn;
use App\Modules\Identity\Events\UserLoggedOut;
use App\Modules\Identity\Events\UserSignedUp;
use App\Modules\Identity\Services\AccountLockoutService;
use App\Modules\Identity\Services\AuthService;

/**
 * Translates auth-related events into rows in `audit_logs`.
 *
 * Why a listener instead of inline AuditLogger calls in {@see AuthService}:
 *   - Audit becomes a single, testable surface that can be swapped or
 *     fanned out to a security pipeline later (Sprint 13+).
 *   - The login codepath stays focused on the credential decision.
 *   - Future side effects (Slack pings, Sentry breadcrumbs) hang off the
 *     same events without touching the service.
 *
 * The {@see AccountLocked} event is NOT handled here — its audit row
 * must be written transactionally with the suspension, so
 * {@see AccountLockoutService} writes the row directly and emits the
 * event for downstream fan-out only. The same pattern applies to the
 * `TwoFactorEnrollmentSuspended` event from chunk 5: the throttle
 * writes the `mfa.enrollment_suspended` row inside the transaction
 * that stamps `users.two_factor_enrollment_suspended_at`, and the
 * event itself is purely a fan-out signal.
 */
final class WriteAuthAuditLog
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handleUserLoggedIn(UserLoggedIn $event): void
    {
        $this->audit->log(
            action: AuditAction::AuthLoginSucceeded,
            actor: $event->user,
            subject: $event->user,
            metadata: [
                'guard' => $event->guard,
                'mfa' => $event->mfa,
            ],
            ip: $event->ip,
            userAgent: $event->userAgent,
        );
    }

    public function handleUserLoggedOut(UserLoggedOut $event): void
    {
        $this->audit->log(
            action: AuditAction::AuthLogout,
            actor: $event->user,
            subject: $event->user,
            metadata: ['guard' => $event->guard],
            ip: $event->ip,
            userAgent: $event->userAgent,
        );
    }

    public function handleLoginFailed(LoginFailed $event): void
    {
        $this->audit->log(
            action: AuditAction::AuthLoginFailed,
            actor: $event->user,
            subject: $event->user,
            metadata: [
                'email' => $event->email,
                'reason' => $event->reason,
            ],
            ip: $event->ip,
            userAgent: $event->userAgent,
        );
    }

    public function handlePasswordResetRequested(PasswordResetRequested $event): void
    {
        $this->audit->log(
            action: AuditAction::AuthPasswordResetRequested,
            actor: $event->user,
            subject: $event->user,
            ip: $event->ip,
            userAgent: $event->userAgent,
        );
    }

    public function handlePasswordResetCompleted(PasswordResetCompleted $event): void
    {
        $this->audit->log(
            action: AuditAction::AuthPasswordResetCompleted,
            actor: $event->user,
            subject: $event->user,
            ip: $event->ip,
            userAgent: $event->userAgent,
        );
    }

    public function handleUserSignedUp(UserSignedUp $event): void
    {
        $this->audit->log(
            action: AuditAction::AuthSignedUp,
            actor: $event->user,
            subject: $event->user,
            ip: $event->ip,
            userAgent: $event->userAgent,
        );
    }

    public function handleEmailVerificationSent(EmailVerificationSent $event): void
    {
        $this->audit->log(
            action: AuditAction::AuthEmailVerificationSent,
            actor: $event->user,
            subject: $event->user,
            ip: $event->ip,
            userAgent: $event->userAgent,
        );
    }

    public function handleEmailVerified(EmailVerified $event): void
    {
        $this->audit->log(
            action: AuditAction::AuthEmailVerified,
            actor: $event->user,
            subject: $event->user,
            ip: $event->ip,
            userAgent: $event->userAgent,
        );
    }

    public function handleTwoFactorEnabled(TwoFactorEnabled $event): void
    {
        $this->audit->log(
            action: AuditAction::MfaEnabled,
            actor: $event->user,
            subject: $event->user,
            ip: $event->ip,
            userAgent: $event->userAgent,
        );
    }

    public function handleTwoFactorConfirmed(TwoFactorConfirmed $event): void
    {
        $this->audit->log(
            action: AuditAction::MfaConfirmed,
            actor: $event->user,
            subject: $event->user,
            ip: $event->ip,
            userAgent: $event->userAgent,
        );
    }

    public function handleTwoFactorDisabled(TwoFactorDisabled $event): void
    {
        $this->audit->log(
            action: AuditAction::MfaDisabled,
            actor: $event->user,
            subject: $event->user,
            ip: $event->ip,
            userAgent: $event->userAgent,
        );
    }

    /**
     * The audit row records the COUNT of codes that were generated, not
     * any of the plaintext code values. The plaintext is shown to the
     * user once at the controller layer and never reaches this listener.
     */
    public function handleTwoFactorRecoveryCodesRegenerated(TwoFactorRecoveryCodesRegenerated $event): void
    {
        $this->audit->log(
            action: AuditAction::MfaRecoveryCodesRegenerated,
            actor: $event->user,
            subject: $event->user,
            metadata: ['code_count' => $event->codeCount],
            ip: $event->ip,
            userAgent: $event->userAgent,
        );
    }

    /**
     * The audit row records the REMAINING code count after consumption,
     * not which code was consumed. Operations can spot a recovery-code
     * burn pattern without ever seeing the bearer token.
     */
    public function handleTwoFactorRecoveryCodeConsumed(TwoFactorRecoveryCodeConsumed $event): void
    {
        $this->audit->log(
            action: AuditAction::MfaRecoveryCodeConsumed,
            actor: $event->user,
            subject: $event->user,
            metadata: ['remaining_count' => $event->remainingCount],
            ip: $event->ip,
            userAgent: $event->userAgent,
        );
    }
}
