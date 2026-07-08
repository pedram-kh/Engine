<?php

declare(strict_types=1);

use App\Core\Enums\Locale;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Catalogue tripwires for the EU Locale enum, mirroring the campaign-enum
 * discipline. The TS<->PHP parity spec (packages/api-client/src/locales.spec.ts)
 * keeps this enum in lockstep with the frontend registry; this test pins the
 * PHP side independently so a drift here is caught even if both layers move.
 */
it('Locale catalogue pins the exact 24-language case set', function (): void {
    $expected = [
        'bg', 'hr', 'cs', 'da', 'nl', 'en', 'et', 'fi', 'fr', 'de',
        'el', 'hu', 'ga', 'it', 'lv', 'lt', 'mt', 'pl', 'pt', 'ro',
        'sk', 'sl', 'es', 'sv',
    ];

    $actual = Locale::values();

    sort($expected);
    sort($actual);

    expect($actual)->toBe($expected, 'Locale enum drifted from the locked 24-language catalogue.');
});

it('UI_LOCALES is the rendered subset and is a subset of all cases', function (): void {
    expect(Locale::uiValues())->toBe([
        'bg', 'hr', 'cs', 'da', 'nl', 'en', 'et', 'fi', 'fr', 'de',
        'el', 'hu', 'ga', 'it', 'lv', 'lt', 'mt', 'pl', 'pt', 'ro',
        'sk', 'sl', 'es', 'sv',
    ]);

    foreach (Locale::uiValues() as $code) {
        expect(in_array($code, Locale::values(), true))->toBeTrue();
    }
});

it('WORLD_LANGUAGES is a superset of the EU cases with valid unique 2-letter codes', function (): void {
    $world = Locale::worldValues();

    // 174 = the 184 ISO 639-1 codes minus the deliberately excluded
    // historical / liturgical / constructed / collection codes.
    expect(count($world))->toBe(174);
    expect(count(array_unique($world)))->toBe(count($world));

    foreach ($world as $code) {
        expect(preg_match('/^[a-z]{2}$/', $code))->toBe(1);
    }

    foreach (Locale::values() as $code) {
        expect(in_array($code, $world, true))->toBeTrue(
            "EU language `{$code}` missing from WORLD_LANGUAGES.",
        );
    }

    // Excluded-by-design codes must stay out (ae Avestan, cu Church
    // Slavonic, la Latin, pi Pali, constructed eo/ia/ie/io/vo, bh).
    foreach (['ae', 'cu', 'la', 'pi', 'eo', 'ia', 'ie', 'io', 'vo', 'bh'] as $excluded) {
        expect(in_array($excluded, $world, true))->toBeFalse(
            "Excluded code `{$excluded}` found in WORLD_LANGUAGES.",
        );
    }
});
