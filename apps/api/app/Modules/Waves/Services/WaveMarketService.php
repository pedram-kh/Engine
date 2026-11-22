<?php

declare(strict_types=1);

namespace App\Modules\Waves\Services;

use App\Modules\Waves\Contracts\WaveMarketGuard;
use App\Modules\Waves\Models\Wave;
use App\Modules\Waves\Models\WaveMarket;
use App\Modules\Waves\Models\WaveMarketCountry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The markets of a wave (PL-2.4): named groups of one or more countries. Every write here runs inside the caller's
 * transaction, on a wave row the caller has already locked FOR UPDATE, so it is serialised with every strategy write
 * (they take the same lock first).
 *
 * Rules held here (the database keys are the belt):
 *   - M2: a country belongs to one market within a wave (`wave.country_in_two_markets`);
 *   - Q4: names are unique within a wave, case-insensitive (`wave.market_name_taken`);
 *   - Q5: short codes are unique within a wave (`wave.market_code_taken`); when omitted the server derives one;
 *   - M6: a market that still has personas cannot be removed (`wave.market_has_personas`, decided by the guard);
 *   - Q12: an edit sends the FULL list keyed by ulid plus `markets_version`; a list built from an older state is refused
 *     whole (409 `wave.markets_changed`), so a stale tab can neither remove a market someone else just added nor undo
 *     a change.
 *
 * Market NAMES are free text typed by the agency and never go into an audit payload; the summary returned here carries
 * ulids and country codes only.
 */
final class WaveMarketService
{
    public const MAX_MARKETS = 20;

    public const MAX_COUNTRIES = 40;

    public function __construct(private readonly WaveMarketGuard $guard) {}

    /**
     * The optimistic-concurrency token of a wave's markets: a hash of the ordered markets (ulid, name, code, countries).
     *
     * @param  iterable<WaveMarket>  $markets  with `countries` loaded, in tab order
     */
    public static function version(iterable $markets): string
    {
        $rows = [];
        foreach ($markets as $market) {
            $countries = $market->countryCodes();
            sort($countries);
            $rows[] = [$market->ulid, $market->name, $market->code, $countries];
        }

        return substr(sha1(json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), 0, 32);
    }

    /**
     * The short code the server derives when none is given, and the one the web suggests (the two must agree).
     * One country: the country code. Otherwise built from the name: an all-capitals word of up to three letters is kept
     * whole, any other word gives its first letter, "and" and "&" are ignored ("UK & Ireland" gives UKI, "North America"
     * gives NA); a single word gives its first three letters ("Nordics" gives NOR). Never shorter than two characters,
     * at most six, and made unique against `$taken` with a numeric suffix.
     *
     * @param  list<string>  $countries
     * @param  list<string>  $taken
     */
    public static function suggestCode(string $name, array $countries, array $taken = []): string
    {
        $base = count($countries) === 1 ? $countries[0] : self::codeFromName($name);
        if (strlen($base) < 2) {
            $base = strtoupper(substr($base.implode('', $countries).'XX', 0, 3));
        }
        $base = substr($base, 0, 6);

        $code = $base;
        for ($n = 2; in_array($code, $taken, true); $n++) {
            $suffix = (string) $n;
            $code = substr($base, 0, 6 - strlen($suffix)).$suffix;
        }

        return $code;
    }

    /**
     * A new wave's markets, inside the create transaction. Returns the audit summary.
     *
     * @param  list<array<string, mixed>>  $specs  `name`, optional `code`, `countries` (already request-validated)
     * @return array{markets: list<string>, market_countries: array<string, list<string>>}
     */
    public function createFor(Wave $wave, array $specs): array
    {
        try {
            DB::transaction(function () use ($wave, $specs): void {
                $this->insertMarkets($wave, $this->normalise($specs), 1);
            });
        } catch (UniqueConstraintViolationException) {
            throw WaveRuleViolation::marketConflict();
        }

        return $this->summary($this->load($wave));
    }

    /**
     * The wave's markets as ulids and country codes (the audit shape), read now.
     *
     * @return array{markets: list<string>, market_countries: array<string, list<string>>}
     */
    public function currentSummary(Wave $wave): array
    {
        return $this->summary($this->load($wave));
    }

    /**
     * Replace the markets of a LOCKED wave with the given full list. A body entry with `id` updates that market, one
     * without creates, an existing market missing from the list is removed (refused if it has personas).
     *
     * @param  list<array<string, mixed>>  $specs  `id?`, `name`, `code?`, `countries`, `rename_persona_codes?`
     * @return array{changed: bool, before: array{markets: list<string>, market_countries: array<string, list<string>>}, after: array{markets: list<string>, market_countries: array<string, list<string>>, markets_renamed: list<string>}, persona_codes: array{renamed: int, skipped: int, kept_custom: int, markets: list<string>}, removed: array{completions: int, notes: int}}
     */
    public function replace(Wave $locked, array $specs, string $version, ?int $confirmNotes = null): array
    {
        $current = $this->load($locked);

        if (! hash_equals(self::version($current), $version)) {
            throw WaveRuleViolation::marketsChanged();
        }

        /** @var Collection<string, WaveMarket> $byUlid */
        $byUlid = $current->keyBy('ulid');
        foreach ($specs as $spec) {
            if (isset($spec['id']) && ! $byUlid->has((string) $spec['id'])) {
                throw WaveRuleViolation::marketUnknown();
            }
        }

        $norm = $this->normalise($specs);

        $kept = [];
        foreach ($norm as $n) {
            if ($n['id'] !== null) {
                $kept[$n['id']] = true;
            }
        }
        $removed = $current->reject(static fn (WaveMarket $m): bool => isset($kept[$m->ulid]));

        if ($removed->isNotEmpty()) {
            $ids = [];
            foreach ($removed as $gone) {
                $ids[] = $gone->id;
            }
            $personas = $this->guard->personaCountsByMarket($locked, $ids);
            if ($personas !== []) {
                $concepts = $this->guard->conceptCountsByMarket($locked, array_keys($personas));
                $rows = [];
                foreach ($current as $market) {
                    if (isset($personas[$market->id])) {
                        $row = ['market' => $market->ulid, 'name' => $market->name, 'code' => $market->code, 'persona_count' => $personas[$market->id]];
                        if (($concepts[$market->id] ?? 0) > 0) {
                            $row['concept_count'] = $concepts[$market->id];
                        }
                        $rows[] = $row;
                    }
                }

                throw WaveRuleViolation::marketHasPersonas($rows);
            }

            // PL-2.5 (Q5): internal notes go with the market, but the person is told how many first (confirm = the total).
            $notes = $this->guard->noteCountsByMarket($locked, $ids);
            $noteTotal = array_sum($notes);
            if ($noteTotal > 0 && $confirmNotes !== $noteTotal) {
                $rows = [];
                foreach ($current as $market) {
                    if (isset($notes[$market->id])) {
                        $rows[] = ['market' => $market->ulid, 'name' => $market->name, 'code' => $market->code, 'note_count' => $notes[$market->id]];
                    }
                }

                throw WaveRuleViolation::marketHasNotes($rows, $noteTotal);
            }
        }

        $before = $this->summary($current);
        $renamed = [];
        $personaCodes = ['renamed' => 0, 'skipped' => 0, 'kept_custom' => 0, 'markets' => []];
        $forgotten = ['completions' => 0, 'notes' => 0];

        try {
            // A nested transaction is a savepoint: a key violation that still gets through (it cannot under the wave
            // lock) must not abort the caller's whole PostgreSQL transaction before it is mapped to a 422.
            DB::transaction(function () use ($locked, $byUlid, $norm, $removed, &$renamed, &$personaCodes, &$forgotten): void {
                // 1. Country rows of removed markets and of markets whose country set changes, so a country can move
                //    between markets in one request without tripping `unique (wave_id, country)` mid-statement.
                $dropFor = $removed->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
                foreach ($norm as $n) {
                    if ($n['id'] === null) {
                        continue;
                    }
                    $existing = $byUlid->get($n['id']);
                    if ($existing === null) {
                        continue;
                    }
                    $had = $existing->countryCodes();
                    sort($had);
                    $want = $n['countries'];
                    sort($want);
                    if ($had !== $want) {
                        $dropFor[] = $existing->id;
                    }
                }
                if ($dropFor !== []) {
                    WaveMarketCountry::query()->where('wave_id', $locked->id)->whereIn('market_id', $dropFor)->delete();
                }

                // 2. Removed markets.
                if ($removed->isNotEmpty()) {
                    // PL-2.5 (Q5): the marks and notes of a removed market go first (every foreign key is RESTRICT).
                    $forgotten = $this->guard->forgetMarkets($locked, array_values($removed->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all()));
                    WaveMarket::query()->where('wave_id', $locked->id)->whereIn('id', $removed->pluck('id')->all())->delete();
                }

                // 3. Name and code changes in two passes (a placeholder first), so two markets can swap names or codes.
                $changing = [];
                foreach ($norm as $n) {
                    $existing = $n['id'] === null ? null : $byUlid->get($n['id']);
                    if ($existing !== null && ($existing->name !== $n['name'] || $existing->code !== $n['code'])) {
                        $changing[] = [$existing, $n];
                    }
                }
                foreach ($changing as [$existing]) {
                    WaveMarket::query()->whereKey($existing->id)->update(['name' => '#'.$existing->ulid, 'code' => '#'.str_pad((string) $existing->id, 5, '0', STR_PAD_LEFT)]);
                }
                foreach ($changing as [$existing, $n]) {
                    WaveMarket::query()->whereKey($existing->id)->update(['name' => $n['name'], 'code' => $n['code']]);
                    $renamed[] = $existing->ulid;
                }

                // 3b. A1: personas follow a short-code change when (and only when) the edit asked for it for that market.
                foreach ($changing as [$existing, $n]) {
                    if (($n['rename_persona_codes'] ?? false) !== true || $existing->code === $n['code']) {
                        continue;
                    }
                    $done = $this->guard->renamePersonaCodes($locked, $existing->id, $existing->code, $n['code']);
                    $personaCodes['renamed'] += $done['renamed'];
                    $personaCodes['skipped'] += $done['skipped'];
                    $personaCodes['kept_custom'] += $done['kept_custom'];
                    $personaCodes['markets'][] = $existing->ulid;
                }

                // 4. New markets and the countries of every changed or new market, then the tab order.
                $position = 0;
                $positions = [];
                foreach ($norm as $n) {
                    $position++;
                    $existing = $n['id'] === null ? null : $byUlid->get($n['id']);
                    if ($existing === null) {
                        $created = WaveMarket::query()->create(['agency_id' => $locked->agency_id, 'wave_id' => $locked->id, 'name' => $n['name'], 'code' => $n['code'], 'position' => $position]);
                        $this->insertCountries($locked, $created, $n['countries']);

                        continue;
                    }
                    if (in_array($existing->id, $dropFor, true)) {
                        $this->insertCountries($locked, $existing, $n['countries']);
                    }
                    $positions[$existing->id] = $position;
                }
                foreach ($positions as $id => $pos) {
                    WaveMarket::query()->whereKey($id)->where('position', '!=', $pos)->update(['position' => $pos]);
                }
            });
        } catch (UniqueConstraintViolationException) {
            throw WaveRuleViolation::marketConflict();
        }

        $after = $this->summary($this->load($locked));
        $changed = $before !== $after || $renamed !== [];

        return [
            'changed' => $changed,
            'before' => $before,
            'after' => $after + ['markets_renamed' => $renamed],
            'persona_codes' => $personaCodes,
            'removed' => $forgotten,
        ];
    }

    private static function codeFromName(string $name): string
    {
        $ascii = Str::ascii($name);
        /** @var list<string> $words */
        $words = preg_split('/[^A-Za-z0-9]+/', $ascii, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_values(array_filter($words, static fn (string $w): bool => strtolower($w) !== 'and'));

        if (count($words) === 0) {
            return '';
        }
        if (count($words) === 1) {
            return strtoupper(substr($words[0], 0, 3));
        }

        $code = '';
        foreach ($words as $word) {
            $code .= (strlen($word) <= 3 && $word === strtoupper($word) && preg_match('/[A-Z]/', $word) === 1)
                ? $word
                : strtoupper($word[0]);
        }

        return strtoupper(substr($code, 0, 6));
    }

    /**
     * Validate the cross-market rules and give every market a name, a code and a list of unique countries.
     *
     * @param  list<array<string, mixed>>  $specs
     * @return list<array{id: string|null, name: string, code: string, countries: list<string>, rename_persona_codes?: bool}>
     */
    private function normalise(array $specs): array
    {
        $names = [];
        $countryOwner = [];
        $explicit = [];
        $rows = [];

        foreach ($specs as $spec) {
            $name = trim((string) preg_replace('/\s+/u', ' ', (string) $spec['name']));
            $key = mb_strtolower($name);
            if (isset($names[$key])) {
                throw WaveRuleViolation::marketNameTaken($name);
            }
            $names[$key] = true;

            $countries = array_values(array_unique(array_map(static fn (mixed $c): string => strtoupper((string) $c), (array) $spec['countries'])));
            foreach ($countries as $country) {
                if (isset($countryOwner[$country])) {
                    throw WaveRuleViolation::countryInTwoMarkets($country, $countryOwner[$country], $name);
                }
                $countryOwner[$country] = $name;
            }

            $code = isset($spec['code']) && $spec['code'] !== '' ? strtoupper((string) $spec['code']) : null;
            if ($code !== null) {
                if (isset($explicit[$code])) {
                    throw WaveRuleViolation::marketCodeTaken($code);
                }
                $explicit[$code] = true;
            }

            $rows[] = ['id' => isset($spec['id']) ? (string) $spec['id'] : null, 'name' => $name, 'code' => $code, 'countries' => $countries, 'rename_persona_codes' => filter_var($spec['rename_persona_codes'] ?? false, FILTER_VALIDATE_BOOLEAN)];
        }

        $taken = array_keys($explicit);
        foreach ($rows as $i => $row) {
            if ($row['code'] === null) {
                $rows[$i]['code'] = self::suggestCode($row['name'], $row['countries'], $taken);
                $taken[] = $rows[$i]['code'];
            }
        }

        /** @var list<array{id: string|null, name: string, code: string, countries: list<string>, rename_persona_codes?: bool}> $rows */
        return $rows;
    }

    /**
     * @param  list<array{id: string|null, name: string, code: string, countries: list<string>, rename_persona_codes?: bool}>  $norm
     */
    private function insertMarkets(Wave $wave, array $norm, int $firstPosition): void
    {
        $position = $firstPosition;
        foreach ($norm as $n) {
            $market = WaveMarket::query()->create(['agency_id' => $wave->agency_id, 'wave_id' => $wave->id, 'name' => $n['name'], 'code' => $n['code'], 'position' => $position++]);
            $this->insertCountries($wave, $market, $n['countries']);
        }
    }

    /**
     * @param  list<string>  $countries
     */
    private function insertCountries(Wave $wave, WaveMarket $market, array $countries): void
    {
        $now = now();
        WaveMarketCountry::query()->insert(array_map(static fn (string $country): array => [
            'agency_id' => $wave->agency_id,
            'wave_id' => $wave->id,
            'market_id' => $market->id,
            'country' => $country,
            'created_at' => $now,
        ], $countries));
    }

    /**
     * @return Collection<int, WaveMarket>
     */
    private function load(Wave $wave): Collection
    {
        $wave->unsetRelation('markets');

        return WaveMarket::query()->where('wave_id', $wave->id)->with('countries')->orderBy('position')->orderBy('id')->get();
    }

    /**
     * Ulids and country codes only (no name): the audit shape.
     *
     * @param  Collection<int, WaveMarket>  $markets
     * @return array{markets: list<string>, market_countries: array<string, list<string>>}
     */
    private function summary(Collection $markets): array
    {
        $countries = [];
        foreach ($markets as $market) {
            $codes = $market->countryCodes();
            sort($codes);
            $countries[$market->ulid] = $codes;
        }

        $ulids = [];
        foreach ($markets as $market) {
            $ulids[] = $market->ulid;
        }

        return ['markets' => $ulids, 'market_countries' => $countries];
    }
}
