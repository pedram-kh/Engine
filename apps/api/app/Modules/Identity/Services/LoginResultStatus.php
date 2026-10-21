<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

enum LoginResultStatus: string
{
    case Success = 'success';
    case InvalidCredentials = 'invalid_credentials';
    case MfaRequired = 'mfa_required';
    case MfaInvalidCode = 'mfa_invalid_code';
    case MfaRateLimited = 'mfa_rate_limited';
    case MfaEnrollmentSuspended = 'mfa_enrollment_suspended';
    case AccountSuspended = 'account_suspended';
    case TemporarilyLocked = 'temporarily_locked';
    /**
     * Password (and second factor) are right but the creator has asked for the
     * account to be deleted and the grace has not ended (AH-104b-del A4).
     * Mapped to `auth.deletion_pending` 403. No session, no token, no failure
     * counter; the client may repeat the same request with `cancel_deletion`.
     */
    case DeletionPending = 'deletion_pending';
    /**
     * Credentials are valid but the (guard, user.type) combination is not
     * allowed for the SPA the request hit. Mapped to `auth.wrong_spa` 403.
     * The session is NOT attached. See AuthService::login() for the
     * allow-list and docs/04-API-DESIGN.md §4 for the rationale.
     */
    case WrongSpa = 'wrong_spa';
}
