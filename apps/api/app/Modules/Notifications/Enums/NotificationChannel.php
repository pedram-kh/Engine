<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Enums;

/**
 * The delivery channels a notification preference can toggle (S11.0 Chunk 1,
 * D-7). Per docs/03-DATA-MODEL.md §14.
 *
 * Computed default resolution (preserve-current): a MISSING preference row
 * resolves to the channel's {@see self::defaultEnabled()} — `in_app` and
 * `email` default ON, `digest` defaults OFF. No per-user row is seeded, so the
 * Ch2 retrofit can never silently disable an existing email.
 *
 * `digest` is present-but-unconsumed this chunk — the Messaging sprint is its
 * first consumer. This chunk's NotificationService reads only `in_app`.
 */
enum NotificationChannel: string
{
    case InApp = 'in_app';
    case Email = 'email';
    case Digest = 'digest';

    /**
     * The preserve-current default when no preference row exists (D-7).
     */
    public function defaultEnabled(): bool
    {
        return match ($this) {
            self::InApp, self::Email => true,
            self::Digest => false,
        };
    }
}
