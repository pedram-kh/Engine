<?php

declare(strict_types=1);

namespace App\Modules\Audit\Enums;

use App\Modules\Audit\Concerns\Audited;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;

/**
 * Catalogue of audit-loggable actions.
 *
 * Sprint 1 / chunk 2 ships only the auth-related verbs needed by the
 * Identity, Audit, and Authorization modules plus the minimum `user.*`
 * verbs required to exercise the {@see Audited}
 * trait on the {@see User} model.
 *
 * Subsequent chunks (3-8) extend this enum with their own verbs (creator.*,
 * agency.*, campaign.*, payment.*, etc.) when they implement those modules.
 *
 * Naming convention (docs/03-DATA-MODEL.md §12, docs/05-SECURITY-COMPLIANCE.md §3.2):
 * `<subject>.<verb>` in lowercase dot notation. Subject is the singular,
 * lower-case form of the model or domain noun the action operates on.
 */
enum AuditAction: string
{
    case AuthLoginSucceeded = 'auth.login.succeeded';
    case AuthLoginFailed = 'auth.login.failed';
    case AuthLogout = 'auth.logout';
    case AuthPasswordResetRequested = 'auth.password.reset_requested';
    case AuthPasswordResetCompleted = 'auth.password.reset_completed';
    case AuthPasswordChanged = 'auth.password.changed';
    case AuthEmailVerificationRequested = 'auth.email.verification_requested';
    case AuthEmailVerified = 'auth.email.verified';
    case AuthTwoFactorEnabled = 'auth.two_factor.enabled';
    case AuthTwoFactorDisabled = 'auth.two_factor.disabled';
    case AuthTwoFactorChallengeSucceeded = 'auth.two_factor.challenge_succeeded';
    case AuthTwoFactorChallengeFailed = 'auth.two_factor.challenge_failed';
    case AuthAccountLocked = 'auth.account_locked';
    case AuthAccountUnlocked = 'auth.account_unlocked';

    case UserCreated = 'user.created';
    case UserUpdated = 'user.updated';
    case UserDeleted = 'user.deleted';
    case UserSuspended = 'user.suspended';
    case UserUnsuspended = 'user.unsuspended';

    /**
     * True when the action requires a non-empty reason at the service layer.
     *
     * Mirrors docs/05-SECURITY-COMPLIANCE.md §3.3. The HTTP layer enforces
     * this via the `action.reason` middleware (X-Action-Reason header).
     * The service layer enforces it in {@see AuditLogger::log()}.
     */
    public function requiresReason(): bool
    {
        return match ($this) {
            self::AuthAccountUnlocked,
            self::UserSuspended,
            self::UserUnsuspended,
            self::UserDeleted => true,
            default => false,
        };
    }
}
