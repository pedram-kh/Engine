<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Services;

use App\Modules\Strategy\Models\WaveMarketScriptLanguage;
use App\Modules\Strategy\Models\WaveScriptHookText;
use App\Modules\Strategy\Models\WaveScriptLineText;
use Illuminate\Support\Facades\DB;

/**
 * READ side of the market script languages (PL-3.2b, rulings 4 and 10). English is always a script language and is never a
 * row; the LOCAL languages are the rows of `wave_market_script_languages`, in `position` order. The writer is
 * {@see ScriptLanguageService}.
 */
final class ScriptLanguages
{
    public const string ENGLISH = 'en';

    /**
     * The local languages of one market, in the order the editor shows them.
     *
     * @return list<string>
     */
    public static function localOf(int $marketId): array
    {
        return array_values(WaveMarketScriptLanguage::query()->where('market_id', $marketId)->orderBy('position')->orderBy('id')->pluck('language')->all());
    }

    /**
     * The languages a script of the market is written in: English first, then the local ones.
     *
     * @return list<string>
     */
    public static function allOf(int $marketId): array
    {
        return [self::ENGLISH, ...self::localOf($marketId)];
    }

    /**
     * Per language, in how many scripts of the market text exists in it (a text that is kept but hidden counts: it is what a
     * removal would leave behind). Two queries, whatever the number of languages or scripts.
     *
     * @param  list<string>  $languages
     * @return array<string, array{scripts: int, texts: int}>
     */
    public static function usage(int $waveId, int $marketId, array $languages): array
    {
        if ($languages === []) {
            return [];
        }
        $out = array_fill_keys($languages, ['scripts' => 0, 'texts' => 0]);
        $scriptIds = DB::table('wave_scripts')->where('wave_id', $waveId)->where('market_id', $marketId)->pluck('id')->all();
        if ($scriptIds === []) {
            return $out;
        }

        $perLanguage = [];
        foreach ([WaveScriptHookText::class, WaveScriptLineText::class] as $model) {
            $rows = $model::query()->withoutGlobalScopes()->whereIn('script_id', $scriptIds)->whereIn('language', $languages)
                ->get(['script_id', 'language']);
            foreach ($rows as $row) {
                $perLanguage[$row->language]['texts'] = ($perLanguage[$row->language]['texts'] ?? 0) + 1;
                $perLanguage[$row->language]['ids'][$row->script_id] = true;
            }
        }
        foreach ($languages as $language) {
            $out[$language] = ['scripts' => count($perLanguage[$language]['ids'] ?? []), 'texts' => $perLanguage[$language]['texts'] ?? 0];
        }

        return $out;
    }

    /**
     * The words of one block, by the estimate rule (ruling 2): the English text; where English is empty, the first local language
     * of the market that has text. Everything else is empty.
     *
     * @param  array<string, string>  $byLanguage  language => text
     * @param  list<string>  $local  the market's local languages in order
     */
    public static function pick(array $byLanguage, array $local): string
    {
        $english = $byLanguage[self::ENGLISH] ?? '';
        if (trim($english) !== '') {
            return $english;
        }
        foreach ($local as $language) {
            if (trim($byLanguage[$language] ?? '') !== '') {
                return $byLanguage[$language];
            }
        }

        return '';
    }
}
