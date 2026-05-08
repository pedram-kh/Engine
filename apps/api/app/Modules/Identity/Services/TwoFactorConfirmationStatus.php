<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

enum TwoFactorConfirmationStatus: string
{
    case Confirmed = 'confirmed';
    case InvalidCode = 'invalid_code';
    case ProvisionalNotFound = 'provisional_not_found';
    case AlreadyConfirmed = 'already_confirmed';
}
