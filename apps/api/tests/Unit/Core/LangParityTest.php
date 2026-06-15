<?php

declare(strict_types=1);

use App\Core\Enums\Locale;
use Tests\TestCase;

uses(TestCase::class);

/**
 * en-SOT parity gate for the backend `lang/` tree (EU-locale S7) — the
 * server-side mirror of the SPA `i18n-locale-parity` specs.
 *
 * For every rendered UI locale (`Locale::UI_LOCALES` — the SAME registry the
 * SetLocale middleware clamps to) this pins, against the `en` source of truth:
 *
 *   1. FILE PARITY        — the locale ships exactly en's set of domain files.
 *   2. KEYSET PARITY      — each file exposes exactly en's dotted key-set, so a
 *                           `trans()` call can never fall through to en for one
 *                           locale while resolving in another.
 *   3. PLACEHOLDER PARITY — each string carries the same `:named` Laravel
 *                           placeholders as its en source (case-insensitive:
 *                           `:Name`/`:NAME` are render-time variants of `:name`).
 *
 * Backend strings use no `|` pluralisation today (the only plural message is the
 * frontend `incomplete_blocker`), so there is no form-count gate here; if a
 * pluralised lang string is ever added, the SPA plural spec's pattern is the
 * one to port.
 */
// Resolved from __DIR__ (tests/Unit/Core) rather than lang_path(): this file's
// top level runs at Pest COLLECTION time, before the app container is booted.
$langRoot = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'lang';
$sotLocale = 'en';

/**
 * @return list<string> sorted relative `*.php` file names in lang/{locale}
 */
$domainFiles = function (string $locale) use ($langRoot): array {
    $dir = $langRoot.DIRECTORY_SEPARATOR.$locale;
    // scandir, not glob: the absolute project path can contain `[`/`]` which
    // glob would interpret as a character class and silently match nothing.
    $entries = is_dir($dir) ? (scandir($dir) ?: []) : [];
    $names = array_values(array_filter(
        $entries,
        static fn (string $name): bool => str_ends_with($name, '.php'),
    ));
    sort($names);

    return $names;
};

/**
 * Flatten a nested lang array into dotted-key => string-value pairs.
 *
 * @param  array<string, mixed>  $node
 * @return array<string, string>
 */
$flatten = function (array $node, string $prefix = '') use (&$flatten): array {
    $out = [];
    foreach ($node as $key => $value) {
        $dotted = $prefix === '' ? (string) $key : $prefix.'.'.$key;
        if (is_array($value)) {
            $out += $flatten($value, $dotted);
        } elseif (is_string($value)) {
            $out[$dotted] = $value;
        }
    }

    return $out;
};

/**
 * @param  string  $locale
 * @param  string  $file
 * @return array<string, string>
 */
$loadLeaves = function (string $locale, string $file) use ($langRoot, $flatten): array {
    $loaded = require $langRoot.DIRECTORY_SEPARATOR.$locale.DIRECTORY_SEPARATOR.$file;

    return is_array($loaded) ? $flatten($loaded) : [];
};

/**
 * @return list<string> sorted, lower-cased `:named` placeholders in a string
 */
$placeholders = function (string $value): array {
    preg_match_all('/:([A-Za-z][A-Za-z0-9_]*)/', $value, $matches);
    $names = array_values(array_unique(array_map('strtolower', $matches[1])));
    sort($names);

    return $names;
};

$targetLocales = array_values(array_filter(
    Locale::uiValues(),
    static fn (string $locale): bool => $locale !== $sotLocale,
));

it('every UI locale ships exactly en\'s set of lang files', function () use ($domainFiles, $sotLocale, $targetLocales): void {
    $sot = $domainFiles($sotLocale);
    expect($sot)->not->toBeEmpty();

    foreach ($targetLocales as $locale) {
        expect($domainFiles($locale))->toBe($sot, "{$locale} lang files drifted from en");
    }
});

it('every UI locale exposes exactly en\'s key-set, file by file', function () use ($domainFiles, $loadLeaves, $sotLocale, $targetLocales): void {
    $violations = [];

    foreach ($domainFiles($sotLocale) as $file) {
        $en = $loadLeaves($sotLocale, $file);
        foreach ($targetLocales as $locale) {
            $target = $loadLeaves($locale, $file);
            foreach (array_keys($en) as $key) {
                if (! array_key_exists($key, $target)) {
                    $violations[] = "{$locale}/{$file}: MISSING {$key}";
                }
            }
            foreach (array_keys($target) as $key) {
                if (! array_key_exists($key, $en)) {
                    $violations[] = "{$locale}/{$file}: EXTRA   {$key}";
                }
            }
        }
    }

    expect($violations)->toBe([], "keyset drift from en:\n".implode("\n", $violations));
});

it('every string carries the same :named placeholders as its en source', function () use ($domainFiles, $loadLeaves, $placeholders, $sotLocale, $targetLocales): void {
    $violations = [];

    foreach ($domainFiles($sotLocale) as $file) {
        $en = $loadLeaves($sotLocale, $file);
        foreach ($targetLocales as $locale) {
            $target = $loadLeaves($locale, $file);
            foreach ($en as $key => $enValue) {
                if (! array_key_exists($key, $target)) {
                    continue; // keyset gate already reports the miss
                }
                $enTokens = $placeholders($enValue);
                $targetTokens = $placeholders($target[$key]);
                if ($enTokens !== $targetTokens) {
                    $violations[] = sprintf(
                        '%s/%s: %s placeholders [%s] != en [%s]',
                        $locale,
                        $file,
                        $key,
                        implode(',', $targetTokens),
                        implode(',', $enTokens),
                    );
                }
            }
        }
    }

    expect($violations)->toBe([], "placeholder drift from en:\n".implode("\n", $violations));
});
