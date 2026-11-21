<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Services;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Brands\Models\Brand;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Enums\ConceptOrigin;
use App\Modules\Strategy\Enums\ConceptStatus;
use App\Modules\Strategy\Enums\FunnelStage;
use App\Modules\Strategy\Enums\MatrixLevel;
use App\Modules\Strategy\Models\WaveConcept;
use App\Modules\Strategy\Models\WaveConceptDecision;
use App\Modules\Strategy\Models\WaveConceptImage;
use App\Modules\Strategy\Models\WaveConceptLink;
use App\Modules\Strategy\Models\WaveConceptUsp;
use App\Modules\Strategy\Models\WaveMatrixCell;
use App\Modules\Strategy\Models\WavePersona;
use App\Modules\Strategy\Models\WaveUsp;
use App\Modules\Strategy\StrategyLimits;
use App\Modules\Waves\Models\Wave;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Concepts of a market (PL-2.3): list, create, edit, duplicate, delete, order. Lock order is always wave, then concept
 * (plan section 1.10). The rules in one place:
 *
 *   - at most 30 concepts per market, counted under the wave lock (create and duplicate);
 *   - the USP chips are a SNAPSHOT of the persona's Priority and Support cells taken when the persona is picked; changing
 *     the persona needs `usp_mode` keep or refill (ruling Q11);
 *   - an edit that changes what the client saw sends an APPROVED concept back to Awaiting; a no-op does not; a Changes
 *     requested concept stays (ruling Q6). {@see markEdited} is the one place images and links call too;
 *   - a concept the client has decided on is deleted by admin or manager only (decided under the lock, ruling Q4).
 */
final class ConceptService
{
    /** Scalar columns an edit may change, besides the persona, the stage and the chips. */
    private const array TEXT_KEYS = ['title', 'funnel_note', 'product', 'angle', 'primary_message', 'hypothesis', 'hook', 'format_visual', 'payoff', 'cta'];

    /**
     * One market's concepts with everything a card shows, in a constant number of queries (concepts, persona, chips and
     * their USPs, ready images, links, the latest decision of each and who recorded it).
     *
     * @return Collection<int, WaveConcept>
     */
    public function list(Wave $wave, string $market): Collection
    {
        PersonaService::assertMarketInWave($wave, $market);

        /** @var Collection<int, WaveConcept> $concepts */
        $concepts = WaveConcept::query()->where('wave_id', $wave->id)->where('market', $market)->orderBy('position')->orderBy('id')->get();

        return self::hydrate($concepts);
    }

    /**
     * @param  Collection<int, WaveConcept>  $concepts
     * @return Collection<int, WaveConcept>
     */
    public static function hydrate(Collection $concepts): Collection
    {
        if ($concepts->isEmpty()) {
            return $concepts;
        }

        $concepts->load([
            'persona',
            'chips.usp',
            'images' => static fn ($q) => $q->where('status', WaveConceptImage::READY),
            'links',
        ]);

        $ids = $concepts->modelKeys();
        $latest = WaveConceptDecision::query()
            ->whereIn('id', static fn ($q) => $q->from('wave_concept_decisions')->selectRaw('max(id)')->whereIn('concept_id', $ids)->groupBy('concept_id'))
            ->with('recordedBy:id,ulid,name')
            ->get()
            ->keyBy('concept_id');

        foreach ($concepts as $concept) {
            $concept->setRelation('latestDecision', $latest->get($concept->id));
        }

        return $concepts;
    }

    /**
     * @param  array<string, mixed>  $data  market, persona (ulid), title and any optional field (already cleaned)
     */
    public function create(Brand $brand, Wave $wave, User $actor, array $data): WaveConcept
    {
        StrategyGuard::assertBrandWritable($brand);
        $market = (string) $data['market'];
        PersonaService::assertMarketInWave($wave, $market);

        return DB::transaction(function () use ($wave, $actor, $data, $market): WaveConcept {
            $locked = StrategyGuard::lockWave($wave);

            $persona = $this->persona($locked, $market, (string) $data['persona']);
            $existing = WaveConcept::query()->where('wave_id', $locked->id)->where('market', $market)->get(['id', 'position']);
            if ($existing->count() >= StrategyLimits::CONCEPT_MAX_PER_MARKET) {
                throw StrategyRuleViolation::conceptMarketFull($market);
            }

            $attributes = [
                'agency_id' => $locked->agency_id,
                'wave_id' => $locked->id,
                'market' => $market,
                'persona_id' => $persona->id,
                'position' => (int) $existing->max('position') + 1,
                'title' => (string) $data['title'],
                'funnel_stage' => $data['funnel_stage'] ?? null,
                'status' => ConceptStatus::Awaiting,
                'origin' => ConceptOrigin::Manual,
            ];
            foreach ([...self::TEXT_KEYS, 'key_points'] as $key) {
                if ($key !== 'title' && array_key_exists($key, $data)) {
                    $attributes[$key] = $data[$key] === [] ? null : $data[$key];
                }
            }
            $concept = WaveConcept::query()->create($attributes);

            $chips = isset($data['usps']) && is_array($data['usps'])
                ? $this->uspIds($locked, $data['usps'])
                : $this->prefill($locked, $persona);
            $this->setChips($concept, $chips);

            Audit::log(
                action: AuditAction::WaveConceptCreated,
                actor: $actor,
                subject: $concept,
                // Literal keys on purpose (AuditPayloadKeysGuardTest reads them statically). Never a title or a text.
                metadata: ['wave' => $locked->ulid, 'concept' => $concept->ulid, 'market' => $market, 'persona' => $persona->ulid, 'chips' => count($chips)],
                agencyId: $locked->agency_id,
            );

            return $concept;
        });
    }

    /**
     * A partial body: only the keys present are applied, and only what really changes is written (a no-op writes nothing,
     * audits nothing and does not reset an approval).
     *
     * @param  array<string, mixed>  $data
     * @return array{concept: WaveConcept, reset_to_awaiting: bool, changed: list<string>}
     */
    public function update(Brand $brand, Wave $wave, string $conceptUlid, User $actor, array $data): array
    {
        StrategyGuard::assertBrandWritable($brand);

        return DB::transaction(function () use ($wave, $conceptUlid, $actor, $data): array {
            $locked = StrategyGuard::lockWave($wave);
            $concept = $this->lockedConcept($locked, $conceptUlid);

            $changed = [];
            $newChips = null;

            foreach (self::TEXT_KEYS as $key) {
                if (array_key_exists($key, $data) && $data[$key] !== $concept->getAttribute($key)) {
                    $concept->setAttribute($key, $data[$key]);
                    $changed[] = $key;
                }
            }
            if (array_key_exists('funnel_stage', $data)) {
                $stage = $data['funnel_stage'] === null ? null : FunnelStage::from((string) $data['funnel_stage']);
                if ($stage !== $concept->funnel_stage) {
                    $concept->funnel_stage = $stage;
                    $changed[] = 'funnel_stage';
                }
            }
            if (array_key_exists('key_points', $data)) {
                $points = $data['key_points'] === [] || $data['key_points'] === null ? null : array_values($data['key_points']);
                if ($points !== $concept->key_points) {
                    $concept->key_points = $points;
                    $changed[] = 'key_points';
                }
            }

            if (isset($data['persona'])) {
                $persona = $this->persona($locked, $concept->market, (string) $data['persona']);
                if ($persona->id !== $concept->persona_id) {
                    if (! isset($data['usps'])) {
                        $mode = $data['usp_mode'] ?? null;
                        if ($mode !== 'keep' && $mode !== 'refill') {
                            throw StrategyRuleViolation::conceptUspModeRequired();
                        }
                        if ($mode === 'refill') {
                            $newChips = $this->prefill($locked, $persona);
                        }
                    }
                    $concept->persona_id = $persona->id;
                    $changed[] = 'persona';
                }
            }
            if (isset($data['usps']) && is_array($data['usps'])) {
                $newChips = $this->uspIds($locked, $data['usps']);
            }
            if ($newChips !== null) {
                $current = WaveConceptUsp::query()->where('concept_id', $concept->id)->orderBy('position')->orderBy('id')->pluck('usp_id')->map(static fn (mixed $i): int => (int) $i)->all();
                if ($current === $newChips) {
                    $newChips = null;
                } else {
                    $changed[] = 'usps';
                }
            }

            if ($changed === []) {
                return ['concept' => $concept, 'reset_to_awaiting' => false, 'changed' => []];
            }

            $concept->save();
            if ($newChips !== null) {
                $this->setChips($concept, $newChips);
            }
            $reset = self::resetIfApproved($concept);

            Audit::log(
                action: AuditAction::WaveConceptUpdated,
                actor: $actor,
                subject: $concept,
                metadata: [
                    'wave' => $locked->ulid,
                    'concept' => $concept->ulid,
                    'market' => $concept->market,
                    'fields_changed' => $changed,
                    'chips' => WaveConceptUsp::query()->where('concept_id', $concept->id)->count(),
                    'reset_to_awaiting' => $reset,
                ],
                agencyId: $locked->agency_id,
            );

            return ['concept' => $concept, 'reset_to_awaiting' => $reset, 'changed' => $changed];
        });
    }

    /**
     * The one place an edit of what the client saw is turned into "back to Awaiting" (ruling Q6). Call inside the
     * transaction, on the locked concept, after the change was made. Returns true when it reset.
     */
    public static function resetIfApproved(WaveConcept $concept): bool
    {
        if ($concept->status !== ConceptStatus::Approved) {
            return false;
        }
        $concept->status = ConceptStatus::Awaiting;
        $concept->save();

        return true;
    }

    /**
     * Object-first delete (plan section 1.8): every stored object goes first (a missing one counts as deleted); if any
     * delete is not confirmed nothing is removed and a retry is safe. Then, in one transaction under the wave and concept
     * locks, the rows go; if an upload finished meanwhile the whole thing runs once more.
     */
    public function delete(Brand $brand, Wave $wave, string $conceptUlid, User $actor, bool $mayDeleteDecided): void
    {
        StrategyGuard::assertBrandWritable($brand);

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $concept = $this->find($wave, $conceptUlid);
            if ($concept->status !== ConceptStatus::Awaiting && ! $mayDeleteDecided) {
                throw StrategyRuleViolation::conceptDeleteDecidedForbidden();
            }

            $keys = $this->imageKeys($concept);
            if (! ConceptImageStorage::deleteAll($keys)) {
                throw StrategyRuleViolation::conceptDeleteFailed();
            }

            $done = DB::transaction(function () use ($wave, $conceptUlid, $actor, $mayDeleteDecided, $keys): bool {
                $locked = StrategyGuard::lockWave($wave);
                $concept = $this->lockedConcept($locked, $conceptUlid);
                if ($concept->status !== ConceptStatus::Awaiting && ! $mayDeleteDecided) {
                    throw StrategyRuleViolation::conceptDeleteDecidedForbidden();
                }
                if ($this->imageKeys($concept) !== $keys) {
                    return false;   // an upload finished meanwhile: delete its object too, then try again
                }

                $counts = $this->purgeRows($concept);
                $this->renumber($locked->id, $concept->market);

                Audit::log(
                    action: AuditAction::WaveConceptDeleted,
                    actor: $actor,
                    subject: $concept,
                    metadata: ['wave' => $locked->ulid, 'concept' => $concept->ulid, 'market' => $concept->market] + $counts,
                    agencyId: $locked->agency_id,
                );

                return true;
            });

            if ($done) {
                return;
            }
        }

        throw StrategyRuleViolation::conflict();
    }

    /**
     * The copy never shares an object with the original (plan section 1.8): transaction 1 makes the concept, its chips,
     * links and one PENDING image row per original image (new keys); the objects are copied server-side; transaction 2
     * marks them ready. A failed copy removes the copy; a crash leaves pending rows for the sweeper.
     */
    public function duplicate(Brand $brand, Wave $wave, string $conceptUlid, User $actor): WaveConcept
    {
        StrategyGuard::assertBrandWritable($brand);

        /** @var list<array{image: WaveConceptImage, from: string, thumbFrom: string|null}> $plan */
        $plan = [];

        $copy = DB::transaction(function () use ($wave, $conceptUlid, $actor, &$plan): WaveConcept {
            $locked = StrategyGuard::lockWave($wave);
            $source = $this->lockedConcept($locked, $conceptUlid);

            $count = WaveConcept::query()->where('wave_id', $locked->id)->where('market', $source->market)->count();
            if ($count >= StrategyLimits::CONCEPT_MAX_PER_MARKET) {
                throw StrategyRuleViolation::conceptMarketFull($source->market);
            }

            $attributes = $source->only(['agency_id', 'wave_id', 'market', 'persona_id', 'funnel_stage', 'funnel_note', 'product', 'angle', 'primary_message', 'hypothesis', 'hook', 'format_visual', 'key_points', 'payoff', 'cta']);
            $attributes['position'] = (int) WaveConcept::query()->where('wave_id', $locked->id)->where('market', $source->market)->max('position') + 1;
            $attributes['title'] = Str::limit($source->title.' (copy)', StrategyLimits::CONCEPT_TITLE, '');
            $attributes['status'] = ConceptStatus::Awaiting;
            $attributes['origin'] = ConceptOrigin::Manual;
            $copy = WaveConcept::query()->create($attributes);

            $this->setChips($copy, WaveConceptUsp::query()->where('concept_id', $source->id)->orderBy('position')->orderBy('id')->pluck('usp_id')->map(static fn (mixed $i): int => (int) $i)->all());

            $links = 0;
            foreach (WaveConceptLink::query()->where('concept_id', $source->id)->orderBy('position')->orderBy('id')->get() as $link) {
                WaveConceptLink::query()->create(['agency_id' => $locked->agency_id, 'wave_id' => $locked->id, 'market' => $copy->market, 'concept_id' => $copy->id, 'url' => $link->url, 'title' => $link->title, 'position' => $link->position]);
                $links++;
            }

            $brand = $locked->brand()->first();
            $agency = $locked->agency()->first();
            $prefix = $brand !== null && $agency !== null ? ConceptImageStorage::conceptPrefix($agency, $brand, $locked, $copy) : null;
            $images = 0;
            foreach (WaveConceptImage::query()->where('concept_id', $source->id)->where('status', WaveConceptImage::READY)->orderBy('position')->orderBy('id')->get() as $image) {
                $ulid = (string) Str::ulid();
                $ext = pathinfo($image->storage_key, PATHINFO_EXTENSION);
                $new = WaveConceptImage::query()->create([
                    'ulid' => $ulid,
                    'agency_id' => $locked->agency_id,
                    'wave_id' => $locked->id,
                    'market' => $copy->market,
                    'concept_id' => $copy->id,
                    'storage_key' => ConceptImageStorage::imageKey((string) $prefix, $ulid, $ext),
                    'original_filename' => $image->original_filename,
                    'mime_type' => $image->mime_type,
                    'declared_bytes' => (int) $image->size_bytes,
                    'status' => WaveConceptImage::PENDING,
                    'expires_at' => now()->addMinutes(20),
                    'position' => $image->position,
                    'uploaded_by_user_id' => $image->uploaded_by_user_id,
                ]);
                $plan[] = ['image' => $new, 'from' => $image->storage_key, 'thumbFrom' => $image->thumb_key];
                $images++;
            }

            Audit::log(
                action: AuditAction::WaveConceptDuplicated,
                actor: $actor,
                subject: $copy,
                metadata: ['wave' => $locked->ulid, 'concept' => $copy->ulid, 'from_concept' => $source->ulid, 'market' => $copy->market, 'images' => $images, 'links' => $links],
                agencyId: $locked->agency_id,
            );

            return $copy;
        });

        $copied = [];
        $failed = false;
        foreach ($plan as $item) {
            $image = $item['image'];
            $thumb = null;
            if (! ConceptImageStorage::copy($item['from'], $image->storage_key)) {
                $failed = true;

                break;
            }
            if ($item['thumbFrom'] !== null) {
                $thumbKey = ConceptImageStorage::thumbKey(ConceptImageStorage::prefixOfKey($image->storage_key), $image->ulid);
                if (ConceptImageStorage::copy($item['thumbFrom'], $thumbKey)) {
                    $thumb = $thumbKey;
                }
            }
            $copied[] = [$image, $thumb];
        }

        if ($failed) {
            // Remove everything the copy owns: the objects made so far (a missing one counts as deleted) and the rows.
            $keys = [];
            foreach ($plan as $item) {
                $keys[] = $item['image']->storage_key;
                $keys[] = ConceptImageStorage::thumbKey(ConceptImageStorage::prefixOfKey($item['image']->storage_key), $item['image']->ulid);
            }
            $gone = ConceptImageStorage::deleteAll($keys);
            if ($gone) {
                DB::transaction(function () use ($copy, $wave): void {
                    StrategyGuard::lockWave($wave);
                    $fresh = WaveConcept::query()->whereKey($copy->id)->lockForUpdate()->first();
                    if ($fresh !== null) {
                        $this->purgeRows($fresh);
                        $this->renumber($fresh->wave_id, $fresh->market);
                    }
                });
            }
            // Not confirmed (a rare storage fault): the pending rows stay as the sweeper's handle.

            throw StrategyRuleViolation::conceptDuplicateFailed();
        }

        DB::transaction(function () use ($copied): void {
            foreach ($copied as [$image, $thumb]) {
                $image->forceFill([
                    'size_bytes' => (int) ConceptImageStorage::disk()->size($image->storage_key),
                    'status' => WaveConceptImage::READY,
                    'expires_at' => null,
                    'thumb_key' => $thumb,
                ])->save();
            }
        });

        return $copy;
    }

    /**
     * The persona's Priority cells, then its Support cells, each by USP position (a Low cell never): the chips a new
     * concept gets (ruling C3).
     *
     * @return list<int> USP ids in chip order
     */
    public function prefill(Wave $wave, WavePersona $persona): array
    {
        $ids = [];
        foreach ([MatrixLevel::Priority, MatrixLevel::Support] as $level) {
            $rows = WaveMatrixCell::query()
                ->where('persona_id', $persona->id)
                ->where('level', $level->value)
                ->join('wave_usps', 'wave_usps.id', '=', 'wave_matrix_cells.usp_id')
                ->orderBy('wave_usps.position')->orderBy('wave_usps.id')
                ->pluck('wave_matrix_cells.usp_id');
            foreach ($rows as $id) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    /**
     * Closes the gaps in the chip order of the given concepts (after a USP's chips were removed).
     *
     * @param  array<mixed>  $conceptIds
     */
    public static function renumberChips(array $conceptIds): void
    {
        foreach (WaveConceptUsp::query()->whereIn('concept_id', $conceptIds)->orderBy('concept_id')->orderBy('position')->orderBy('id')->get()->groupBy('concept_id') as $chips) {
            foreach ($chips->values() as $i => $chip) {
                if ($chip->position !== $i + 1) {
                    $chip->position = $i + 1;
                    $chip->save();
                }
            }
        }
    }

    public function find(Wave $wave, string $ulid): WaveConcept
    {
        $concept = WaveConcept::query()->where('wave_id', $wave->id)->where('ulid', $ulid)->first();
        if ($concept === null) {
            throw StrategyRuleViolation::conceptNotFound();
        }

        return $concept;
    }

    /** The concept, locked FOR UPDATE; call inside a transaction, after {@see StrategyGuard::lockWave}. */
    public function lockedConcept(Wave $wave, string $ulid): WaveConcept
    {
        $concept = WaveConcept::query()->where('wave_id', $wave->id)->where('ulid', $ulid)->lockForUpdate()->first();
        if ($concept === null) {
            throw StrategyRuleViolation::conceptNotFound();
        }

        return $concept;
    }

    /**
     * What step 4 shows for the stepper and the warning of steps 5 and 6 (no lock, ruling C6): counts across the wave and
     * per market. A market is complete when it has at least one concept and every concept is approved.
     *
     * @return array{total: int, approved: int, awaiting: int, changes_requested: int, markets_complete: int, by_market: list<array{market: string, total: int, approved: int, awaiting: int, changes_requested: int, complete: bool, under_minimum: bool}>}
     */
    public static function summary(Wave $wave): array
    {
        $rows = WaveConcept::query()->where('wave_id', $wave->id)->selectRaw('market, status, count(*) as n')->groupBy('market', 'status')->get();
        $by = [];
        foreach ($wave->markets as $code) {
            $by[$code] = ['market' => $code, 'total' => 0, 'approved' => 0, 'awaiting' => 0, 'changes_requested' => 0];
        }
        foreach ($rows as $row) {
            $market = (string) $row->getAttribute('market');
            $status = (string) $row->getAttribute('status');
            $n = (int) $row->getAttribute('n');
            $by[$market] ??= ['market' => $market, 'total' => 0, 'approved' => 0, 'awaiting' => 0, 'changes_requested' => 0];
            $by[$market]['total'] += $n;
            if (isset($by[$market][$status])) {
                $by[$market][$status] += $n;
            }
        }

        $out = ['total' => 0, 'approved' => 0, 'awaiting' => 0, 'changes_requested' => 0, 'markets_complete' => 0, 'by_market' => []];
        foreach ($by as $m) {
            $m['complete'] = $m['total'] > 0 && $m['approved'] === $m['total'];
            $m['under_minimum'] = $m['total'] < StrategyLimits::CONCEPT_MIN_RECOMMENDED;
            $out['total'] += $m['total'];
            $out['approved'] += $m['approved'];
            $out['awaiting'] += $m['awaiting'];
            $out['changes_requested'] += $m['changes_requested'];
            $out['markets_complete'] += $m['complete'] ? 1 : 0;
            $out['by_market'][] = $m;
        }

        return $out;
    }

    /** @return array{chips_removed: int, images_removed: int, links_removed: int, decisions_removed: int} */
    private function purgeRows(WaveConcept $concept): array
    {
        $chips = WaveConceptUsp::query()->where('concept_id', $concept->id)->delete();
        $images = WaveConceptImage::query()->where('concept_id', $concept->id)->delete();
        $links = WaveConceptLink::query()->where('concept_id', $concept->id)->delete();
        $decisions = WaveConceptDecision::query()->where('concept_id', $concept->id)->delete();
        $concept->delete();

        return ['chips_removed' => $chips, 'images_removed' => $images, 'links_removed' => $links, 'decisions_removed' => $decisions];
    }

    /** Every object key of every image row of a concept (pending ones too), sorted: the set a delete must remove. @return list<string> */
    private function imageKeys(WaveConcept $concept): array
    {
        $keys = [];
        foreach (WaveConceptImage::query()->where('concept_id', $concept->id)->get() as $image) {
            foreach (ConceptImageStorage::keysOf($image) as $key) {
                $keys[] = $key;
            }
        }
        sort($keys);

        return $keys;
    }

    /** Positions 1..n in a market, after a delete (not a unique key: a renumber never collides). */
    private function renumber(int $waveId, string $market): void
    {
        $position = 0;
        foreach (WaveConcept::query()->where('wave_id', $waveId)->where('market', $market)->orderBy('position')->orderBy('id')->get() as $c) {
            $position++;
            if ($c->position !== $position) {
                $c->position = $position;
                $c->save();
            }
        }
    }

    /** @param  array<int>  $uspIds */
    private function setChips(WaveConcept $concept, array $uspIds): void
    {
        WaveConceptUsp::query()->where('concept_id', $concept->id)->delete();
        foreach (array_values($uspIds) as $i => $uspId) {
            WaveConceptUsp::query()->create([
                'agency_id' => $concept->agency_id,
                'wave_id' => $concept->wave_id,
                'market' => $concept->market,
                'concept_id' => $concept->id,
                'usp_id' => $uspId,
                'position' => $i + 1,
            ]);
        }
    }

    /**
     * @param  array<mixed>  $ulids
     * @return list<int>
     */
    private function uspIds(Wave $wave, array $ulids): array
    {
        $found = WaveUsp::query()->where('wave_id', $wave->id)->whereIn('ulid', $ulids)->pluck('id', 'ulid');
        $ids = [];
        foreach ($ulids as $ulid) {
            if (! is_string($ulid) || ! $found->has($ulid) || in_array((int) $found[$ulid], $ids, true)) {
                throw StrategyRuleViolation::conceptUspInvalid();
            }
            $ids[] = (int) $found[$ulid];
        }

        return $ids;
    }

    private function persona(Wave $wave, string $market, string $ulid): WavePersona
    {
        $persona = WavePersona::query()->where('wave_id', $wave->id)->where('market', $market)->where('ulid', $ulid)->first();
        if ($persona === null) {
            throw StrategyRuleViolation::conceptPersonaInvalid();
        }

        return $persona;
    }
}
