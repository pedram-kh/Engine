<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Push;

/**
 * A vendor-NEUTRAL push message (AH-102, plan §5.1). No FCM/APNs field names
 * live here — the sender owns the mapping.
 *
 * Privacy: this DTO is the ONLY thing a sender ever sees, and it is built by
 * the message factory from an allowlist — never from a notification's `data`
 * bag. Whatever is in `title`/`body`/`data` here leaves the building and lands
 * on a lock screen, so nothing is added to it casually.
 */
final readonly class PushMessage
{
    /** The one Android notification channel the app creates (plan §5.5). */
    public const string DEFAULT_CHANNEL = 'catalyst_default';

    /**
     * @param  array<string, string|int>  $data  small scalar payload for the app (type, ulid, version) — never user content; senders stringify
     * @param  string|null  $collapseKey  messages sharing a key REPLACE each other on the device
     * @param  string  $channelId  Android notification channel
     * @param  int  $ttlSeconds  how long the provider may hold an undeliverable message
     */
    public function __construct(
        public string $title,
        public ?string $body = null,
        public array $data = [],
        public ?string $collapseKey = null,
        public string $channelId = self::DEFAULT_CHANNEL,
        public int $ttlSeconds = 86400,
        public PushPriority $priority = PushPriority::High,
    ) {}
}
