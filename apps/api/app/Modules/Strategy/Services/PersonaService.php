<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Services;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Brands\Models\Brand;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Enums\PersonaPronoun;
use App\Modules\Strategy\Models\WavePersona;
use App\Modules\Strategy\Models\WavePersonaAngle;
use App\Modules\Strategy\StrategyLimits;
use App\Modules\Waves\Models\Wave;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Personas, per market of a wave (PL-2.1, plan sections 1.2, 1.9, 3.2, 3.3).
 *
 *   - the wave row is locked FIRST in every write, so the cap of 8 per market, the first-free slot and a wave edit that
 *     removes a market are all decided one at a time;
 *   - the market must be one of the wave's markets (checked under the lock; there is no markets table);
 *   - the slot is the first free one of 1..8 in the market and fixes display order and colour; the default code is
 *     `<market><slot>` and an edited code must be unique inside the market;
 *   - copy takes the lock once, is all-or-nothing, and brings the angles along (the USPs are the same wave's).
 *
 * One audit row per request, ids, market codes, counts and field NAMES; never the persona name or any card text.
 */
final class PersonaService
{
    public const FIELDS = ['code', 'title', 'name', 'pronoun', 'summary', 'core_job', 'need', 'drivers', 'goals', 'self_talk', 'barriers'];

    /**
     * @return Collection<int, WavePersona>
     */
    public function list(Wave $wave, ?string $market): Collection
    {
        if ($market !== null) {
            self::assertMarketInWave($wave, $market);
        }

        $order = array_flip(array_values($wave->markets));

        return WavePersona::query()
            ->where('wave_id', $wave->id)
            ->when($market !== null, static fn ($q) => $q->where('market', $market))
            ->with(['angles.usp'])
            ->orderBy('slot')->orderBy('id')
            ->get()
            ->sortBy(static fn (WavePersona $p): int => ($order[$p->market] ?? 99) * 100 + $p->slot)
            ->values();
    }

    /**
     * @param  array<string, mixed>  $data  market + the card fields (code optional)
     */
    public function create(Brand $brand, Wave $wave, User $actor, array $data): WavePersona
    {
        StrategyGuard::assertBrandWritable($brand);
        $market = (string) $data['market'];

        return StrategyGuard::retryOnClash(fn (): WavePersona => DB::transaction(function () use ($wave, $actor, $data, $market): WavePersona {
            $locked = StrategyGuard::lockWave($wave);
            self::assertMarketInWave($locked, $market);

            $existing = WavePersona::query()->where('wave_id', $locked->id)->where('market', $market)->get(['id', 'slot', 'code']);
            if ($existing->count() >= StrategyLimits::PERSONA_MAX) {
                throw StrategyRuleViolation::personaMaxReached($market);
            }

            $slot = self::firstFreeSlot(self::slotsOf($existing));
            $taken = self::codesOf($existing);
            if (isset($data['code'])) {
                if (in_array($data['code'], $taken, true)) {
                    throw StrategyRuleViolation::personaCodeTaken((string) $data['code']);
                }
                $code = (string) $data['code'];
            } else {
                $code = self::defaultCode($market, $slot, $taken);
            }

            $persona = WavePersona::query()->create(self::attributes($data) + [
                'pronoun' => PersonaPronoun::They,
                'agency_id' => $locked->agency_id,
                'wave_id' => $locked->id,
                'market' => $market,
                'slot' => $slot,
                'code' => $code,
            ]);

            Audit::log(
                action: AuditAction::WavePersonaCreated,
                actor: $actor,
                subject: $persona,
                metadata: ['wave' => $locked->ulid, 'persona' => $persona->ulid, 'market' => $market],
                agencyId: $locked->agency_id,
            );

            return $persona;
        }));
    }

    /**
     * A partial body: only the keys present are applied; the market never changes.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Brand $brand, Wave $wave, string $personaUlid, User $actor, array $data): WavePersona
    {
        StrategyGuard::assertBrandWritable($brand);

        return StrategyGuard::retryOnClash(fn (): WavePersona => DB::transaction(function () use ($wave, $personaUlid, $actor, $data): WavePersona {
            $locked = StrategyGuard::lockWave($wave);
            $persona = $this->find($locked, $personaUlid);

            if (isset($data['code']) && $data['code'] !== $persona->code) {
                $clash = WavePersona::query()
                    ->where('wave_id', $locked->id)->where('market', $persona->market)
                    ->where('code', $data['code'])->where('id', '!=', $persona->id)->exists();
                if ($clash) {
                    throw StrategyRuleViolation::personaCodeTaken((string) $data['code']);
                }
            }

            foreach (self::FIELDS as $field) {
                if (array_key_exists($field, $data)) {
                    $persona->{$field} = $data[$field];
                }
            }

            $changed = array_values(array_filter(self::FIELDS, static fn (string $f): bool => $persona->isDirty($f)));
            if ($changed === []) {
                return $persona;
            }
            $persona->save();

            Audit::log(
                action: AuditAction::WavePersonaUpdated,
                actor: $actor,
                subject: $persona,
                metadata: ['wave' => $locked->ulid, 'persona' => $persona->ulid, 'market' => $persona->market, 'changed' => $changed],
                agencyId: $locked->agency_id,
            );

            return $persona;
        }));
    }

    /** Removes the persona and its angles in one transaction. */
    public function delete(Brand $brand, Wave $wave, string $personaUlid, User $actor): void
    {
        StrategyGuard::assertBrandWritable($brand);

        DB::transaction(function () use ($wave, $personaUlid, $actor): void {
            $locked = StrategyGuard::lockWave($wave);
            $persona = $this->find($locked, $personaUlid);

            $angles = WavePersonaAngle::query()->where('persona_id', $persona->id)->count();
            WavePersonaAngle::query()->where('persona_id', $persona->id)->delete();
            $persona->delete();

            Audit::log(
                action: AuditAction::WavePersonaDeleted,
                actor: $actor,
                subject: $persona,
                metadata: ['wave' => $locked->ulid, 'persona' => $persona->ulid, 'market' => $persona->market, 'angles_removed' => $angles],
                agencyId: $locked->agency_id,
            );
        });
    }

    /**
     * Copy personas (default all) from one market to another of the same wave, with their angles. All or nothing.
     *
     * @param  list<string>|null  $personaUlids
     * @return Collection<int, WavePersona>
     */
    public function copy(Brand $brand, Wave $wave, User $actor, string $from, string $to, ?array $personaUlids): Collection
    {
        StrategyGuard::assertBrandWritable($brand);

        return StrategyGuard::retryOnClash(fn (): Collection => DB::transaction(function () use ($wave, $actor, $from, $to, $personaUlids): Collection {
            $locked = StrategyGuard::lockWave($wave);
            self::assertMarketInWave($locked, $from);
            self::assertMarketInWave($locked, $to);
            if ($from === $to) {
                throw StrategyRuleViolation::personaCopyInvalid();
            }

            $sources = WavePersona::query()
                ->where('wave_id', $locked->id)->where('market', $from)
                ->when($personaUlids !== null, static fn ($q) => $q->whereIn('ulid', $personaUlids))
                ->with('angles')
                ->orderBy('slot')->orderBy('id')
                ->get();

            $wanted = $personaUlids === null ? $sources->count() : count(array_unique($personaUlids));
            if ($sources->isEmpty() || $sources->count() !== $wanted) {
                throw StrategyRuleViolation::personaCopyInvalid();
            }

            $existing = WavePersona::query()->where('wave_id', $locked->id)->where('market', $to)->get(['id', 'slot', 'code']);
            if ($existing->count() + $sources->count() > StrategyLimits::PERSONA_MAX) {
                throw StrategyRuleViolation::personaCopyExceedsCap($existing->count(), $sources->count());
            }

            $slots = self::slotsOf($existing);
            $codes = self::codesOf($existing);
            $created = new Collection;

            foreach ($sources as $source) {
                $slot = self::firstFreeSlot($slots);
                $code = self::defaultCode($to, $slot, $codes);
                $slots[] = $slot;
                $codes[] = $code;

                $copy = WavePersona::query()->create([
                    'agency_id' => $locked->agency_id,
                    'wave_id' => $locked->id,
                    'market' => $to,
                    'slot' => $slot,
                    'code' => $code,
                ] + $source->only(['title', 'name', 'pronoun', 'summary', 'core_job', 'need', 'drivers', 'goals', 'self_talk', 'barriers']));

                foreach ($source->angles as $angle) {
                    WavePersonaAngle::query()->create([
                        'agency_id' => $locked->agency_id,
                        'wave_id' => $locked->id,
                        'persona_id' => $copy->id,
                        'usp_id' => $angle->usp_id,
                        'explanation' => $angle->explanation,
                        'origin' => $angle->origin,
                    ]);
                }
                $created->push($copy->load('angles.usp'));
            }

            Audit::log(
                action: AuditAction::WavePersonaCopied,
                actor: $actor,
                subject: $locked,
                metadata: ['wave' => $locked->ulid, 'from_market' => $from, 'to_market' => $to, 'personas_copied' => $created->count()],
                agencyId: $locked->agency_id,
            );

            return $created;
        }));
    }

    public static function assertMarketInWave(Wave $wave, string $market): void
    {
        if (! in_array($market, $wave->markets, true)) {
            throw StrategyRuleViolation::personaMarketNotInWave($market);
        }
    }

    /**
     * @param  Collection<int, WavePersona>  $personas
     * @return list<int>
     */
    private static function slotsOf(Collection $personas): array
    {
        return array_values($personas->map(static fn (WavePersona $p): int => $p->slot)->all());
    }

    /**
     * @param  Collection<int, WavePersona>  $personas
     * @return list<string>
     */
    private static function codesOf(Collection $personas): array
    {
        return array_values($personas->map(static fn (WavePersona $p): string => $p->code)->all());
    }

    /** @param  list<int>  $used */
    private static function firstFreeSlot(array $used): int
    {
        for ($slot = 1; $slot <= StrategyLimits::PERSONA_MAX; $slot++) {
            if (! in_array($slot, $used, true)) {
                return $slot;
            }
        }

        throw StrategyRuleViolation::conflict();   // unreachable under the cap check
    }

    /**
     * `<market><slot>`; if a person edited another persona's code into it, the next free `<market><slot>A`..`Z`.
     *
     * @param  list<string>  $taken
     */
    private static function defaultCode(string $market, int $slot, array $taken): string
    {
        $code = $market.$slot;
        foreach (array_merge([''], range('A', 'Z')) as $suffix) {
            if (! in_array($code.$suffix, $taken, true)) {
                return $code.$suffix;
            }
        }

        throw StrategyRuleViolation::conflict();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function attributes(array $data): array
    {
        $out = [];
        foreach (self::FIELDS as $field) {
            if ($field !== 'code' && array_key_exists($field, $data)) {
                $out[$field] = $data[$field];
            }
        }

        return $out;
    }

    private function find(Wave $wave, string $ulid): WavePersona
    {
        return WavePersona::query()
            ->where('ulid', $ulid)
            ->where('wave_id', $wave->id)
            ->where('agency_id', $wave->agency_id)
            ->firstOrFail();
    }
}
