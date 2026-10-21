<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

/** Outcome of {@see AccountDeletionService::request()}. */
enum AccountDeletionStatus: string
{
    case Requested = 'requested';
    case AlreadyPending = 'already_pending';
    case InvalidPassword = 'invalid_password';
    case MfaRequired = 'mfa_required';
    case MfaInvalidCode = 'mfa_invalid_code';
    case MfaRateLimited = 'mfa_rate_limited';
    case MfaEnrollmentSuspended = 'mfa_enrollment_suspended';
    case Blocked = 'blocked';
    case NotAvailable = 'not_available';
}
