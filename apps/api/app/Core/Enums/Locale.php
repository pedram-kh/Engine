<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * The 24 official EU languages (ISO 639-1), the backend mirror of the
 * frontend `EU_LANGUAGES` registry in `packages/api-client`.
 *
 * Three locale concepts (see docs/00-MASTER-ARCHITECTURE.md §13):
 *   - The enum CASES are the 24 EU languages — the set used to validate
 *     an agency/brand default CONTENT language (campaigns are produced
 *     in operating-market languages).
 *   - {@see self::UI_LOCALES} is the subset we actually RENDER, used to
 *     validate `preferred_language`. Accepting a UI locale we cannot
 *     render would silently fall back to `en`, so the rendered set is
 *     validated separately. It flips to the full 24 as the final action
 *     of the locale generation pass (S8).
 *   - {@see self::WORLD_LANGUAGES} is every living ISO 639-1 language,
 *     used to validate a CREATOR's spoken-language metadata
 *     (`primary_language`, `secondary_languages`) — creators come from
 *     anywhere, so speaker metadata is legitimately the world set.
 *
 * The enum cases and the `UI_LOCALES` / `WORLD_LANGUAGES` constants are
 * held in lockstep with the TS `EU_LANGUAGES` / `UI_LOCALES` /
 * `WORLD_LANGUAGES` exports by the TS<->PHP parity architecture test
 * (standing standard 5.25).
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
     * Every living natural language with an ISO 639-1 code (each fits
     * `char(2)`). Superset of the enum cases. Excluded on purpose:
     * historical / liturgical codes (ae Avestan, cu Church Slavonic,
     * la Latin, pi Pali), constructed languages (eo, ia, ie, io, vo),
     * and the deprecated collection code bh. Held in lockstep with the
     * frontend `WORLD_LANGUAGES` registry. Use for validating creator
     * spoken-language fields with `Rule::in(Locale::WORLD_LANGUAGES)`.
     */
    public const array WORLD_LANGUAGES = [
        'aa', 'ab', 'af', 'ak', 'am', 'an', 'ar', 'as', 'av', 'ay',
        'az', 'ba', 'be', 'bg', 'bi', 'bm', 'bn', 'bo', 'br', 'bs',
        'ca', 'ce', 'ch', 'co', 'cr', 'cs', 'cv', 'cy', 'da', 'de',
        'dv', 'dz', 'ee', 'el', 'en', 'es', 'et', 'eu', 'fa', 'ff',
        'fi', 'fj', 'fo', 'fr', 'fy', 'ga', 'gd', 'gl', 'gn', 'gu',
        'gv', 'ha', 'he', 'hi', 'ho', 'hr', 'ht', 'hu', 'hy', 'hz',
        'id', 'ig', 'ii', 'ik', 'is', 'it', 'iu', 'ja', 'jv', 'ka',
        'kg', 'ki', 'kj', 'kk', 'kl', 'km', 'kn', 'ko', 'kr', 'ks',
        'ku', 'kv', 'kw', 'ky', 'lb', 'lg', 'li', 'ln', 'lo', 'lt',
        'lu', 'lv', 'mg', 'mh', 'mi', 'mk', 'ml', 'mn', 'mr', 'ms',
        'mt', 'my', 'na', 'nb', 'nd', 'ne', 'ng', 'nl', 'nn', 'no',
        'nr', 'nv', 'ny', 'oc', 'oj', 'om', 'or', 'os', 'pa', 'pl',
        'ps', 'pt', 'qu', 'rm', 'rn', 'ro', 'ru', 'rw', 'sa', 'sc',
        'sd', 'se', 'sg', 'si', 'sk', 'sl', 'sm', 'sn', 'so', 'sq',
        'sr', 'ss', 'st', 'su', 'sv', 'sw', 'ta', 'te', 'tg', 'th',
        'ti', 'tk', 'tl', 'tn', 'to', 'tr', 'ts', 'tt', 'tw', 'ty',
        'ug', 'uk', 'ur', 'uz', 've', 'vi', 'wa', 'wo', 'xh', 'yi',
        'yo', 'za', 'zh', 'zu',
    ];

    /**
     * Every enum case value (the 24 EU languages) — for validating
     * agency/brand content-language fields with
     * `Rule::in(Locale::values())`.
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

    /**
     * The full world spoken-language set — for validating creator
     * `primary_language` / `secondary_languages`.
     *
     * @return list<string>
     */
    public static function worldValues(): array
    {
        return self::WORLD_LANGUAGES;
    }
}
