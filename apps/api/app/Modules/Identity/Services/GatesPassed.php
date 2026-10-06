<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\User;

/**
 * The "every gate cleared" outcome of AuthService's shared gate graph
 * (AH-099). Carries what the two login doors need to finish: the verified
 * user, the normalised email (for clearing the lockout counters), request
 * metadata, and whether 2FA was satisfied.
 */
final readonly class GatesPassed
{
    public function __construct(
        public string $email,
        public User $user,
        public ?string $ip,
        public ?string $userAgent,
        public bool $usedMfa,
    ) {}
}
