<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Services;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Brands\Models\Brand;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Models\WaveMarketScriptLanguage;
use App\Modules\Strategy\StrategyLimits;
use App\Modules\Waves\Models\Wave;
use App\Modules\Waves\Models\WaveMarket;

/**
 * The WRITER of a market's local script languages (PL-3.2b, rulings 4 and 10). The set is replaced as a whole under the wave
 * lock. English is always there and is never a row; at most three local languages. REMOVING a language deletes its row here and
 * NOTHING else: the texts written in it stay in the text tables, hidden, and return when the language is added back.
 */
final class ScriptLanguageService
{
    /**
     * Replace the local languages of a market. `$languages` is the new ordered list of two-letter codes (the request has already
     * refused `en`, a bad code and a duplicate). More than three is refused. Returns what was added and removed.
     *
     * @param  list<string>  $languages
     * @return array{added: list<string>, removed: list<string>}
     */
    public function set(Brand $brand, Wave $wave, User $actor, string $marketUlid, array $languages): array
    {
        StrategyGuard::assertBrandWritable($brand);

        return ScriptGuard::transaction($wave, function (Wave $locked) use ($actor, $marketUlid, $languages): array {
            $market = WaveMarket::query()->where('wave_id', $locked->id)->where('ulid', $marketUlid)->first();
            if ($market === null) {
                throw StrategyRuleViolation::conflict404();
            }
            if (count($languages) > StrategyLimits::LOCAL_LANGUAGES_PER_MARKET) {
                throw StrategyRuleViolation::scriptLanguageLimit();
            }

            $existing = WaveMarketScriptLanguage::query()->where('market_id', $market->id)->lockForUpdate()->get()->keyBy('language');
            $added = array_values(array_diff($languages, $existing->keys()->all()));
            $removed = array_values(array_diff($existing->keys()->all(), $languages));
            $unchanged = $added === [] && $removed === [] && array_values($existing->sortBy('position')->keys()->all()) === $languages;
            if ($unchanged) {
                return ['added' => [], 'removed' => []];
            }

            // How many scripts hold text in a language that goes (counted BEFORE the row goes; the text stays).
            $usage = ScriptLanguages::usage($locked->id, $market->id, $removed);
            $scriptsWithRemoved = 0;
            foreach ($usage as $u) {
                $scriptsWithRemoved += $u['scripts'];
            }

            foreach ($removed as $language) {
                $existing->get($language)?->delete();
            }
            foreach ($languages as $position => $language) {
                $row = $existing->get($language);
                if ($row === null) {
                    WaveMarketScriptLanguage::query()->create(['agency_id' => $locked->agency_id, 'wave_id' => $locked->id, 'market_id' => $market->id, 'language' => $language, 'position' => $position]);
                } elseif ($row->position !== $position) {
                    $row->forceFill(['position' => $position])->save();
                }
            }

            Audit::log(
                action: AuditAction::WaveMarketScriptLanguagesUpdated,
                actor: $actor,
                subject: $market,
                // Literal keys on purpose (AuditPayloadKeysGuardTest reads them statically). Language CODES and a count; no text.
                metadata: ['wave' => $locked->ulid, 'market' => $market->ulid, 'added' => $added, 'removed' => $removed, 'scripts_with_removed' => $scriptsWithRemoved],
                agencyId: $locked->agency_id,
            );

            return ['added' => $added, 'removed' => $removed];
        });
    }
}
