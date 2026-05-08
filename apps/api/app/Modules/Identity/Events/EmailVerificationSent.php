<?php

declare(strict_types=1);

namespace App\Modules\Identity\Events;

use App\Modules\Identity\Listeners\WriteAuthAuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\EmailVerificationService;
use App\Modules\Identity\Services\SignUpService;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Emitted whenever a verification mail is queued for a user — both at
 * sign-up (by {@see SignUpService}) and on
 * resend (by {@see EmailVerificationService}).
 *
 * Listeners:
 *   - {@see WriteAuthAuditLog} records
 *     `auth.email.verification_sent` so we can prove a mail was queued
 *     even if the SMTP gateway later drops it.
 */
final readonly class EmailVerificationSent
{
    use Dispatchable;

    public function __construct(
        public User $user,
        public ?string $ip,
        public ?string $userAgent,
    ) {}
}
