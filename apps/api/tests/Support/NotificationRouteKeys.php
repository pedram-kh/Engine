<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * AH-103 — reads the shared notification route-key contract
 * (`packages/api-client/contracts/notification-route-keys.json`).
 *
 * The SAME file is read by the SPA's Vitest suite, so the emitters, the push
 * factory and the SPA resolver cannot drift apart without a build failing. It is
 * a contract (names only), never sample data.
 */
final class NotificationRouteKeys
{
    /** The only route keys that exist anywhere in the contract. */
    public const array ROUTE_KEYS = ['assignment_ulid', 'campaign_ulid', 'thread_ulid'];

    public static function path(): string
    {
        // tests/Support → tests → apps/api → apps → repo root
        return dirname(__DIR__, 4).DIRECTORY_SEPARATOR.'packages'.DIRECTORY_SEPARATOR
            .'api-client'.DIRECTORY_SEPARATOR.'contracts'.DIRECTORY_SEPARATOR.'notification-route-keys.json';
    }

    /**
     * @return array<string, array{bag: list<string>, push: list<string>}>
     */
    public static function types(): array
    {
        $raw = file_get_contents(self::path());
        if ($raw === false) {
            throw new RuntimeException('Cannot read the route-key contract at '.self::path());
        }

        /** @var array{version: int, types: array<string, array{bag: list<string>, push: list<string>}>} $decoded */
        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        return $decoded['types'];
    }

    /**
     * @return list<string>
     */
    public static function bagKeys(string $type): array
    {
        return self::types()[$type]['bag'] ?? [];
    }

    /**
     * @return list<string>
     */
    public static function pushKeys(string $type): array
    {
        return self::types()[$type]['push'] ?? [];
    }
}
