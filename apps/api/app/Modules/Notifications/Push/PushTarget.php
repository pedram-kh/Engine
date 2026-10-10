<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Push;

/**
 * Where one message goes: one device token on one platform.
 */
final readonly class PushTarget
{
    public function __construct(
        public PushPlatform $platform,
        public string $token,
    ) {}
}
