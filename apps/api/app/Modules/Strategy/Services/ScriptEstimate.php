<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Services;

/**
 * The duration estimate of a script (ruling Q12, restated after the PL-3.2a walk; PL-3.2b ruling 2): an ad uses ONE hook at a time,
 * so the estimate counts the spoken (audio) words of the LONGEST hook only, plus ALL the spoken lines, divided by 2.5 per second
 * and rounded up to a whole second. Each block counts its English text; where that is empty, the first local language of the
 * market that has text ({@see ScriptLanguages::pick()}). The server does the counting, so the browser and a reload agree. Hook texts that are not spoken (visual description, overlay) and the brief are never counted;
 * the sum of all hooks is never taken. Words are whitespace-separated. Never stored. The browser shows the server's number, which
 * is renewed by every save of a hook, so it follows the text once it has been saved.
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
     * @param  list<string>  $hookAudio  the counted audio text of each hook
     * @param  list<string>  $lineText  the counted text of each line
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
