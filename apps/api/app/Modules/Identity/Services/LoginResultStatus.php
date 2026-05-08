<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

enum LoginResultStatus: string
{
    case Success = 'success';
    case InvalidCredentials = 'invalid_credentials';
    case MfaRequired = 'mfa_required';
    case AccountSuspended = 'account_suspended';
    case TemporarilyLocked = 'temporarily_locked';
}
