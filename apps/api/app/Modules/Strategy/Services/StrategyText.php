<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Services;

/**
 * The plain-text rule of every USP and persona field (plan section 1.7). Text is stored AS TYPED (no tag stripping:
 * "A & B" and "<3" are legitimate), minus control characters other than tab and line feed, CRLF turned into LF, and
 * trimmed. It is rendered escaped everywhere (no `v-html`). An empty result is null.
 */
final class StrategyText
{
    public static function clean(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $text = str_replace(["\r\n", "\r"], "\n", $value);
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        $text = trim($text);

        return $text === '' ? null : $text;
    }

    /**
     * A list of short texts: each cleaned, empty items dropped, order kept. A non-array is returned unchanged so the
     * validator can refuse it; an empty result is an empty list.
     */
    public static function cleanList(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $out = [];
        foreach ($value as $item) {
            if ($item === null) {
                continue;   // an empty string arrives as null (ConvertEmptyStringsToNull): dropped like a blank item
            }
            if (! is_string($item)) {
                $out[] = $item;   // left for the validator to refuse

                continue;
            }
            $clean = self::clean($item);
            if ($clean !== null) {
                $out[] = $clean;
            }
        }

        return $out;
    }

    /**
     * Clean the named keys of an input array (only those present); a list key uses {@see cleanList}.
     *
     * @param  array<string, mixed>  $input
     * @param  list<string>  $textKeys
     * @param  list<string>  $listKeys
     * @return array<string, mixed>
     */
    public static function cleanInput(array $input, array $textKeys, array $listKeys = []): array
    {
        foreach ($textKeys as $key) {
            if (array_key_exists($key, $input) && (is_string($input[$key]) || $input[$key] === null)) {
                $input[$key] = self::clean($input[$key]);
            }
        }
        foreach ($listKeys as $key) {
            if (array_key_exists($key, $input)) {
                $input[$key] = self::cleanList($input[$key]);
            }
        }

        return $input;
    }
}
