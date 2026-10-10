<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Push;

/**
 * One finished send attempt, as the recorder sees it. Deliberately carries NO
 * device token and NO message text — only identifiers and the provider-neutral
 * outcome — so whatever the recorder does with it (log today, a
 * `push_deliveries` row in AH-102b) cannot leak either.
 */
final readonly class PushDeliveryOutcome
{
    public function __construct(
        public int $tokenId,
        public int $userId,
        public PushPlatform $platform,
        public string $notificationType,
        public PushOutcome $outcome,
        public int $attempt,
        public ?string $code = null,
        public ?string $detail = null,
        public ?int $httpStatus = null,
        /** True when a Retryable outcome has run out of attempts and will not be tried again. */
        public bool $final = false,
    ) {}
}
