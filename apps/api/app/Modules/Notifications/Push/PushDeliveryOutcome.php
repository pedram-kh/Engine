<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Push;

/**
 * One finished send attempt, as the recorder sees it. Deliberately carries NO
 * device token and NO message text — only identifiers and the provider-neutral
 * outcome — so whatever the recorder does with it (log it, write a
 * `push_deliveries` row) cannot leak either.
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
        /**
         * ULID of the in-app notification this push mirrors (`PushMessage::data['notification_ulid']`),
         * the join key the admin delivery view uses to show in-app read state. Null when the
         * message carries none. AH-102b.
         */
        public ?string $notificationUlid = null,
    ) {}
}
