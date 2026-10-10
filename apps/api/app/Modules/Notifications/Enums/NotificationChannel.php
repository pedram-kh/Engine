<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Enums;

use App\Modules\Notifications\Services\NotificationService;

/**
 * The delivery channels a notification preference can toggle (S11.0 Chunk 1,
 * D-7). Per docs/03-DATA-MODEL.md §14.
 *
 * Computed default resolution (preserve-current): a MISSING preference row
 * resolves to {@see self::defaultEnabledFor()} — the channel baseline
 * ({@see self::defaultEnabled()}: `in_app` and `email` default ON, `digest`
 * defaults OFF) unless a per-TYPE override says otherwise (AH-102). No per-user
 * row is seeded, so the Ch2 retrofit can never silently disable an existing email.
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
     * The per-TYPE default exceptions (AH-102, Q3): `type value => channel value => default`.
     *
     * Deliberately EMPTY of every `in_app` / `email` / `digest` entry — an existing
     * default moving would silently override stored state for every user who has
     * (or lacks) a row (see {@see NotificationService::setPreference()}).
     * The map is pinned by test to exactly what has been ruled.
     *
     * @var array<string, array<string, bool>>
     */
    private const array TYPE_DEFAULT_OVERRIDES = [];

    /**
     * The CHANNEL baseline default (D-7) — the answer when a type has no override.
     *
     * Call sites that have a {@see NotificationType} in hand MUST use
     * {@see self::defaultEnabledFor()} instead; the architecture test
     * `NotificationChannelDefaultArchitectureTest` fails on a direct call from
     * anywhere but this enum.
     */
    public function defaultEnabled(): bool
    {
        return match ($this) {
            self::InApp, self::Email => true,
            self::Digest => false,
        };
    }

    /**
     * The default for a (type, channel) pair when no preference row exists: the
     * type's override if it has one, else the channel baseline. The ONE resolver —
     * the read ({@see NotificationService::isChannelEnabled()}),
     * the sparse write (`setPreference`) and the `defaults` contract all use it.
     */
    public function defaultEnabledFor(NotificationType $type): bool
    {
        return self::typeDefaultOverrides()[$type->value][$this->value] ?? $this->defaultEnabled();
    }

    /**
     * The per-type exceptions, as shipped in the `type_defaults` block of
     * `GET`/`PATCH /me/notification-preferences`.
     *
     * @return array<string, array<string, bool>>
     */
    public static function typeDefaultOverrides(): array
    {
        return self::TYPE_DEFAULT_OVERRIDES;
    }

    /**
     * The channel BASELINE defaults, keyed by channel value — the `defaults`
     * block of `GET`/`PATCH /me/notification-preferences`. Per-type exceptions
     * travel separately ({@see self::typeDefaultOverrides()}).
     *
     * @return array<string, bool>
     */
    public static function baselineDefaults(): array
    {
        $defaults = [];

        foreach (self::cases() as $channel) {
            $defaults[$channel->value] = $channel->defaultEnabled();
        }

        return $defaults;
    }
}
