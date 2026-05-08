<?php

declare(strict_types=1);

namespace App\Modules\Identity\Events;

use App\Modules\Identity\Listeners\WriteAuthAuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\EmailVerificationResult;
use App\Modules\Identity\Services\EmailVerificationService;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Emitted by {@see EmailVerificationService} the
 * first time a user successfully redeems a verification token. Re-clicks
 * on an already-verified user do not re-fire this event — the service
 * short-circuits with {@see EmailVerificationResult::AlreadyVerified}.
 *
 * Listeners:
 *   - {@see WriteAuthAuditLog} records `auth.email.verified`.
 */
final readonly class EmailVerified
{
    use Dispatchable;

    public function __construct(
        public User $user,
        public ?string $ip,
        public ?string $userAgent,
    ) {}
}
