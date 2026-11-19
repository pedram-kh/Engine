<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Enums;

/**
 * The six USP colours, in the S3 order (purple, green, blue, amber, teal, pink). Each key is also a valid brand-palette
 * key (`BrandColor`), so the web maps it with `brandColorVar()` and no new token exists. A USP stores its key; a persona
 * derives its colour from its slot (wrapping the six, ruling Q2).
 */
enum UspColor: string
{
    case Violet = 'violet';
    case Green = 'green';
    case Blue = 'blue';
    case Amber = 'amber';
    case Teal = 'teal';
    case Pink = 'pink';

    /** The colour of persona slot 1..8: the six in order, then wrapping (slot 7 violet, slot 8 green). */
    public static function forSlot(int $slot): self
    {
        $cases = self::cases();

        return $cases[(max(1, $slot) - 1) % count($cases)];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
