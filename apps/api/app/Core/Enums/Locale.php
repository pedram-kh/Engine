<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * The 24 official EU languages (ISO 639-1), the backend mirror of the
 * frontend `EU_LANGUAGES` registry in `packages/api-client`.
 *
 * Two locale concepts (see docs/00-MASTER-ARCHITECTURE.md §13):
 *   - The enum CASES are all 24 languages — the set used to validate
 *     CONTENT-language metadata (creator primary/secondary languages,
 *     agency/brand default content language). Speaker metadata is
 *     legitimately the full 24.
 *   - {@see self::UI_LOCALES} is the subset we actually RENDER, used to
 *     validate `preferred_language`. Accepting a UI locale we cannot
 *     render would silently fall back to `en`, so the rendered set is
 *     validated separately. It flips to the full 24 as the final action
 *     of the locale generation pass (S8).
 *
 * The enum cases and the `UI_LOCALES` constant are held in lockstep with
 * the TS `EU_LANGUAGES` / `UI_LOCALES` exports by the TS<->PHP parity
 * architecture test (standing standard 5.25).
 */
enum Locale: string
{
    case Bulgarian = 'bg';
    case Croatian = 'hr';
    case Czech = 'cs';
    case Danish = 'da';
    case Dutch = 'nl';
    case English = 'en';
    case Estonian = 'et';
    case Finnish = 'fi';
    case French = 'fr';
    case German = 'de';
    case Greek = 'el';
    case Hungarian = 'hu';
    case Irish = 'ga';
    case Italian = 'it';
    case Latvian = 'lv';
    case Lithuanian = 'lt';
    case Maltese = 'mt';
    case Polish = 'pl';
    case Portuguese = 'pt';
    case Romanian = 'ro';
    case Slovak = 'sk';
    case Slovenian = 'sl';
    case Spanish = 'es';
    case Swedish = 'sv';

    /**
     * The UI locales we actually render today. Held in lockstep with the
     * frontend `UI_LOCALES`. Flipped to every case (all 24) as the final
     * action of the generation pass.
     */
    public const array UI_LOCALES = ['bg', 'hr', 'cs', 'da', 'nl', 'en', 'et', 'fi', 'fr', 'de', 'el', 'hu', 'ga', 'it', 'lv', 'lt', 'mt', 'pl', 'pt', 'ro', 'sk', 'sl', 'es', 'sv'];

    /**
     * Every language code (all 24) — for validating content-language
     * fields with `Rule::in(Locale::values())`.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $locale): string => $locale->value, self::cases());
    }

    /**
     * The rendered UI-locale subset — for validating `preferred_language`
     * with `Rule::in(Locale::uiValues())`.
     *
     * @return list<string>
     */
    public static function uiValues(): array
    {
        return self::UI_LOCALES;
    }
}
