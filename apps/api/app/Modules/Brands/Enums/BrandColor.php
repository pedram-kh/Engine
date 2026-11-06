<?php

declare(strict_types=1);

namespace App\Modules\Brands\Enums;

/**
 * AH-119 A4 — the brand palette. A brand stores one of these KEYS; the web maps a key to a theme colour
 * (a dark and a light value each). The order is the default-assignment order: a new brand with no
 * explicit colour takes the key at `(the agency's brand count) mod 8`.
 */
enum BrandColor: string
{
    case Violet = 'violet';
    case Green = 'green';
    case Amber = 'amber';
    case Blue = 'blue';
    case Pink = 'pink';
    case Teal = 'teal';
    case Red = 'red';
    case Slate = 'slate';

    /** The key for the n-th brand an agency creates (0-based), stable and wrapping. */
    public static function nthDefault(int $brandCount): self
    {
        $cases = self::cases();

        return $cases[max(0, $brandCount) % count($cases)];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
