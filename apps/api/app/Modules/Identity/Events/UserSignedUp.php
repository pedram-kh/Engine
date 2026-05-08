<?php

declare(strict_types=1);

namespace App\Modules\Identity\Events;

use App\Modules\Identity\Listeners\WriteAuthAuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\SignUpService;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Emitted by {@see SignUpService} once the new
 * `users` row has been written. The user is created with
 * `email_verified_at = null` and a creator-typed account; no satellite
 * profile rows (creators, admin_profiles, agency_users) are touched —
 * those land in their own sprints.
 *
 * Listeners:
 *   - {@see WriteAuthAuditLog} records `auth.signup` against the user.
 */
final readonly class UserSignedUp
{
    use Dispatchable;

    public function __construct(
        public User $user,
        public ?string $ip,
        public ?string $userAgent,
    ) {}
}
