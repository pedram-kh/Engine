<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Services;

/**
 * The duration estimate of a script (ruling Q12): spoken words divided by 2.5 per second, rounded up to a whole second, for the
 * LONGEST hook (any one hook may be chosen) plus all lines (PL-3.2b). Words are whitespace-separated. Never stored. The browser
 * computes the same number from the text on screen, so the two are pinned by the same cases (backend test and `scriptsLogic.spec.ts`).
 */
final class ScriptEstimate
{
    public const WORDS_PER_SECOND = 2.5;

    public static function words(string $text): int
    {
        $text = trim($text);

        return $text === '' ? 0 : count(preg_split('/\s+/u', $text) ?: []);
    }

    /**
     * @param  list<string>  $hookAudio  the English audio of each hook
     * @param  list<string>  $lineText  the English text of each line (none until PL-3.2b)
     * @return array{words: int, seconds: int}
     */
    public static function of(array $hookAudio, array $lineText = []): array
    {
        $longest = 0;
        foreach ($hookAudio as $audio) {
            $longest = max($longest, self::words($audio));
        }
        $words = $longest + array_sum(array_map(self::words(...), $lineText));

        return ['words' => $words, 'seconds' => (int) ceil($words / self::WORDS_PER_SECOND)];
    }
}
