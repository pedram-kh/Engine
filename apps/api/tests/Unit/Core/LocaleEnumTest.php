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
