<?php

declare(strict_types=1);

/**
 * Pre-flip locale verifier (EU-locale S8) — backend lang/.
 *
 * The standalone mirror of tests/Unit/Core/LangParityTest.php for a locale that
 * is not yet in Locale::UI_LOCALES (so the Pest gate does not iterate it yet).
 * Checks file parity + keyset parity + `:named` placeholder parity against `en`.
 *
 * Usage:  php scripts/i18n/verify-locale.php <locale> [<locale> ...]
 * Exits non-zero (printing every violation) if any locale drifts from en.
 */
$langRoot = dirname(__DIR__, 2).'/apps/api/lang';
$sot = 'en';

$domainFiles = static function (string $locale) use ($langRoot): array {
    $dir = $langRoot.'/'.$locale;
    $entries = is_dir($dir) ? (scandir($dir) ?: []) : [];
    $names = array_values(array_filter($entries, static fn (string $n): bool => str_ends_with($n, '.php')));
    sort($names);

    return $names;
};

$flatten = static function (array $node, string $prefix = '') use (&$flatten): array {
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

$loadLeaves = static function (string $locale, string $file) use ($langRoot, $flatten): array {
    $path = $langRoot.'/'.$locale.'/'.$file;
    if (! is_file($path)) {
        return [];
    }
    $loaded = require $path;

    return is_array($loaded) ? $flatten($loaded) : [];
};

$placeholders = static function (string $value): array {
    preg_match_all('/:([A-Za-z][A-Za-z0-9_]*)/', $value, $m);
    $names = array_values(array_unique(array_map('strtolower', $m[1])));
    sort($names);

    return $names;
};

$locales = array_slice($argv, 1);
if ($locales === []) {
    fwrite(STDERR, "usage: php scripts/i18n/verify-locale.php <locale> [<locale> ...]\n");
    exit(2);
}

$failed = false;
foreach ($locales as $locale) {
    $violations = [];
    $sotFiles = $domainFiles($sot);
    $targetFiles = $domainFiles($locale);

    if ($targetFiles === []) {
        $violations[] = "lang/{$locale} has no .php files";
    } elseif ($targetFiles !== $sotFiles) {
        $violations[] = "lang/{$locale}: file set differs from en";
    }

    foreach ($sotFiles as $file) {
        if (! in_array($file, $targetFiles, true)) {
            continue;
        }
        $en = $loadLeaves($sot, $file);
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
        foreach ($en as $key => $enValue) {
            if (! array_key_exists($key, $target)) {
                continue;
            }
            $enTok = $placeholders($enValue);
            $tgtTok = $placeholders($target[$key]);
            if ($enTok !== $tgtTok) {
                $violations[] = sprintf(
                    '%s/%s: %s placeholders [%s] != en [%s]',
                    $locale, $file, $key, implode(',', $tgtTok), implode(',', $enTok),
                );
            }
        }
    }

    if ($violations === []) {
        echo "PASS  {$locale}  (backend lang/ parity with en)\n";
    } else {
        $failed = true;
        fwrite(STDERR, sprintf("FAIL  %s  (%d violations)\n", $locale, count($violations)));
        foreach ($violations as $v) {
            fwrite(STDERR, '  '.$v."\n");
        }
    }
}

exit($failed ? 1 : 0);
