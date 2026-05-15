<?php

declare(strict_types=1);

namespace App\Modules\Identity\Exceptions;

use App\Modules\Identity\Services\SignUpService;
use RuntimeException;

/**
 * Thrown by {@see SignUpService::register()}
 * when the sign-up payload carries an `invitation_token` and the token
 * is invalid (not found, expired, already accepted, or bound to a
 * different email than the one the user typed).
 *
 * The controller catches this and emits a 422 with the standard error
 * envelope. Allowed codes:
 *
 *   invitation.not_found
 *   invitation.expired
 *   invitation.already_accepted
 *   invitation.email_mismatch
 */
final class InvitationAcceptException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
