<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Services;

use App\Core\Tenancy\CurrentAgencyRole;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Models\WaveCreator;
use App\Modules\Strategy\Models\WaveCreatorDecision;
use App\Modules\Strategy\Models\WavePersona;
use App\Modules\Strategy\StrategyLimits;
use App\Modules\Waves\Models\Wave;
use App\Modules\Waves\Models\WaveMarket;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;

/**
 * Add, edit, replace and remove the creators of a wave (PL-3.1 plan 1.5 and 1.7). EVERY write goes through
 * {@see WaveCreatorGuard::transaction()} (the wave row lock first) and re-checks eligibility inside it, from the database.
 * Nothing here writes a campaign, an assignment or an application: adding, replacing or removing a creator never changes the
 * campaign (gate B22). Release is not here (PL-3.4).
 *
 * Audit rows are written inside the transaction with literal keys: ulids, the slot number, counts, an enum value and a list of
 * changed field NAMES. Never a creator's name, and never a client's name, note or comment.
 */
final class WaveCreatorService
{
    /** PL-3.2a: what happens to a slot's scripts on Replace / Remove. */
    public const CARRY_OVER = 'carry_over';

    public const ARCHIVE = 'archive';

    public const DELETE = 'delete';

    /**
     * Add creators to a market, all or nothing.
     *
     * @param  list<string>  $creatorUlids
     * @return Collection<int, WaveCreator>
     */
    public function add(Brand $brand, Wave $wave, User $actor, string $marketUlid, array $creatorUlids, ?string $personaUlid): Collection
    {
        StrategyGuard::assertBrandWritable($brand);
        $creatorUlids = array_values(array_unique($creatorUlids));

        return WaveCreatorGuard::transaction($wave, function (Wave $locked) use ($actor, $marketUlid, $creatorUlids, $personaUlid): Collection {
            $campaign = $this->campaignOrRefuse($locked);
            $market = PersonaService::assertMarketInWave($locked, $marketUlid);
            $persona = $personaUlid === null ? null : $this->personaOf($locked, $market, $personaUlid);

            $inMarket = WaveCreator::query()->where('wave_id', $locked->id)->where('market_id', $market->id)->get(['slot', 'slot_code']);
            if ($inMarket->count() + count($creatorUlids) > StrategyLimits::CREATORS_PER_MARKET) {
                throw StrategyRuleViolation::creatorCapReached('market', StrategyLimits::CREATORS_PER_MARKET);
            }
            if (WaveCreator::query()->where('wave_id', $locked->id)->count() + count($creatorUlids) > StrategyLimits::CREATORS_PER_WAVE) {
                throw StrategyRuleViolation::creatorCapReached('wave', StrategyLimits::CREATORS_PER_WAVE);
            }

            $creators = WaveCreatorSource::require($locked, $campaign, $creatorUlids);
            $this->assertNotInWave($locked, array_values($creators->pluck('id')->all()));

            $slots = array_values($inMarket->pluck('slot')->map(static fn (mixed $s): int => (int) $s)->all());
            $codes = array_values(array_map('strval', $inMarket->pluck('slot_code')->all()));
            $created = new Collection;
            try {
                foreach ($creatorUlids as $ulid) {
                    $creator = $creators->get($ulid);
                    [$slot, $code] = $this->nextSlot($market, $slots, $codes);
                    $slots[] = $slot;
                    $codes[] = $code;

                    $row = WaveCreator::query()->create([
                        'agency_id' => $locked->agency_id,
                        'wave_id' => $locked->id,
                        'market_id' => $market->id,
                        'creator_id' => $creator?->id,
                        'persona_id' => $persona?->id,
                        'slot' => $slot,
                        'slot_code' => $code,
                        'added_by_user_id' => $actor->id,
                    ]);
                    $created->push($row);
                    // PL-3.2a (Q8): a creator who comes back to the SAME market of this wave gets their archived scripts back.
                    $restored = ScriptArchive::restore($locked, $row, $market, $actor);

                    Audit::log(
                        action: AuditAction::WaveCreatorAdded,
                        actor: $actor,
                        subject: $row,
                        metadata: ['wave' => $locked->ulid, 'market' => $market->ulid, 'slot' => $slot, 'creator_id' => $creator?->id, 'persona' => $persona?->ulid, 'scripts_restored' => $restored],
                        agencyId: $locked->agency_id,
                    );
                }
            } catch (UniqueConstraintViolationException) {
                // The lock makes this unreachable; if it ever fires, say what it means.
                throw StrategyRuleViolation::creatorAlreadyInWave(null);
            }

            return $created;
        });
    }

    /**
     * Edit a slot's code and/or persona. `$changes` holds only the keys the request sent: `slot_code` (string) and `persona`
     * (ulid or null).
     *
     * @param  array{slot_code?: string, persona?: string|null}  $changes
     */
    public function update(Brand $brand, Wave $wave, User $actor, string $slotUlid, array $changes): WaveCreator
    {
        StrategyGuard::assertBrandWritable($brand);

        return WaveCreatorGuard::transaction($wave, function (Wave $locked) use ($actor, $slotUlid, $changes): WaveCreator {
            $slot = $this->lockedSlot($locked, $slotUlid);
            $market = WaveMarket::query()->findOrFail($slot->market_id);
            $fields = [];

            if (array_key_exists('slot_code', $changes) && $changes['slot_code'] !== $slot->slot_code) {
                $taken = WaveCreator::query()->where('wave_id', $locked->id)->where('market_id', $slot->market_id)
                    ->where('slot_code', $changes['slot_code'])->whereKeyNot($slot->id)->exists();
                if ($taken) {
                    throw StrategyRuleViolation::creatorSlotCodeTaken($changes['slot_code']);
                }
                $slot->slot_code = $changes['slot_code'];
                $fields[] = 'slot_code';
            }
            if (array_key_exists('persona', $changes)) {
                $persona = $changes['persona'] === null ? null : $this->personaOf($locked, $market, $changes['persona']);
                if (($persona?->id) !== $slot->persona_id) {
                    $slot->persona_id = $persona?->id;
                    $fields[] = 'persona';
                }
            }

            if ($fields !== []) {
                $slot->save();
                Audit::log(
                    action: AuditAction::WaveCreatorUpdated,
                    actor: $actor,
                    subject: $slot,
                    metadata: ['wave' => $locked->ulid, 'market' => $market->ulid, 'slot' => $slot->slot, 'fields' => $fields],
                    agencyId: $locked->agency_id,
                );
            }

            return $slot;
        });
    }

    /**
     * Replace the creator of a slot: the slot, its code and its persona stay; the old creator's decisions go with the old creator.
     * PL-3.2a (Q8): the old creator's scripts CARRY OVER to the new creator by default (`carry_over`; the slot row is updated in
     * place and the composite key moves them), or are archived (`archive`). The new creator's own archived scripts of this market
     * come back either way. Carrying more than three scripts in all is refused and nothing is written.
     *
     * @param  string  $scripts  `carry_over` or `archive`
     */
    public function replace(Brand $brand, Wave $wave, User $actor, string $slotUlid, string $creatorUlid, string $scripts = self::CARRY_OVER): WaveCreator
    {
        StrategyGuard::assertBrandWritable($brand);

        return WaveCreatorGuard::transaction($wave, function (Wave $locked) use ($actor, $slotUlid, $creatorUlid, $scripts): WaveCreator {
            $campaign = $this->campaignOrRefuse($locked);
            $slot = $this->lockedSlot($locked, $slotUlid);
            $market = WaveMarket::query()->findOrFail($slot->market_id);

            $new = WaveCreatorSource::require($locked, $campaign, [$creatorUlid])->get($creatorUlid);
            if ($new === null) {
                throw StrategyRuleViolation::creatorNotEligible();
            }
            if ($new->id === $slot->creator_id) {
                throw StrategyRuleViolation::creatorReplaceSame();
            }
            $this->assertNotInWave($locked, [$new->id]);

            WaveCreatorBoardRules::assertRemovable($locked, $slot);

            // The new creator is not in this wave, so they hold no LIVE script: the carried scripts keep their numbers (an archived
            // script of theirs does not hold a number; it comes back below, as far as the cap of three allows).
            $live = ScriptArchive::liveOf($slot);
            $carried = $live->isNotEmpty() && $scripts === self::CARRY_OVER ? $live->count() : 0;
            WaveCreatorBoardRules::release($locked, $slot, $actor);

            $previous = $slot->creator_id;
            $archived = $live->isNotEmpty() && $scripts !== self::CARRY_OVER ? ScriptArchive::archive($locked, $slot, $market, $actor) : 0;
            $removed = WaveCreatorDecision::query()->where('wave_creator_id', $slot->id)->delete();
            $slot->creator_id = $new->id;
            $slot->save();
            $restored = ScriptArchive::restore($locked, $slot, $market, $actor);

            Audit::log(
                action: AuditAction::WaveCreatorReplaced,
                actor: $actor,
                subject: $slot,
                metadata: ['wave' => $locked->ulid, 'market' => $market->ulid, 'slot' => $slot->slot, 'creator_id' => $new->id, 'previous_creator_id' => $previous, 'decisions_removed' => $removed, 'scripts_carried' => $carried, 'scripts_archived' => $archived, 'scripts_restored' => $restored],
                agencyId: $locked->agency_id,
            );

            return $slot;
        });
    }

    /**
     * Remove a creator from the wave: the card goes back, the decisions and the slot are deleted, the campaign is untouched.
     * PL-3.2a (Q8): a creator with scripts needs the choice `archive` (kept, restored if the creator is added to this market
     * again) or `delete` (admin and manager only); without it the request is refused and nothing is written.
     *
     * @param  string|null  $scripts  `archive`, `delete` or null
     */
    public function remove(Brand $brand, Wave $wave, User $actor, string $slotUlid, ?string $scripts = null): void
    {
        StrategyGuard::assertBrandWritable($brand);

        WaveCreatorGuard::transaction($wave, function (Wave $locked) use ($actor, $slotUlid, $scripts): void {
            $slot = $this->lockedSlot($locked, $slotUlid);
            $market = WaveMarket::query()->findOrFail($slot->market_id);

            WaveCreatorBoardRules::assertRemovable($locked, $slot);
            $live = ScriptArchive::liveOf($slot);
            if ($live->isNotEmpty()) {
                if ($scripts === null) {
                    throw StrategyRuleViolation::scriptsChoiceRequired($live->count());
                }
                if ($scripts === self::DELETE && ! CurrentAgencyRole::canManage($actor)) {
                    throw StrategyRuleViolation::scriptsDeleteForbidden();
                }
            }
            WaveCreatorBoardRules::release($locked, $slot, $actor);

            $archived = $live->isNotEmpty() && $scripts === self::ARCHIVE ? ScriptArchive::archive($locked, $slot, $market, $actor) : 0;
            $deleted = $live->isNotEmpty() && $scripts === self::DELETE ? ScriptArchive::deleteLive($locked, $slot, $market, $actor) : 0;
            $removed = WaveCreatorDecision::query()->where('wave_creator_id', $slot->id)->delete();
            $slot->delete();

            Audit::log(
                action: AuditAction::WaveCreatorRemoved,
                actor: $actor,
                subject: $slot,
                metadata: ['wave' => $locked->ulid, 'market' => $market->ulid, 'slot' => $slot->slot, 'creator_id' => $slot->creator_id, 'decisions_removed' => $removed, 'scripts_archived' => $archived, 'scripts_deleted' => $deleted],
                agencyId: $locked->agency_id,
            );
        });
    }

    /** The slot of a wave by ulid, locked FOR UPDATE (after the wave lock, always). A slot of another wave is a 404. */
    public function lockedSlot(Wave $locked, string $ulid): WaveCreator
    {
        $slot = WaveCreator::query()->where('wave_id', $locked->id)->where('ulid', $ulid)->lockForUpdate()->first();
        if ($slot === null) {
            throw StrategyRuleViolation::conflict404();
        }

        return $slot;
    }

    public function find(Wave $wave, string $ulid): WaveCreator
    {
        $slot = WaveCreator::query()->where('wave_id', $wave->id)->where('ulid', $ulid)->first();
        if ($slot === null) {
            throw StrategyRuleViolation::conflict404();
        }

        return $slot;
    }

    private function campaignOrRefuse(Wave $locked): Campaign
    {
        $campaign = WaveCreatorSource::campaign($locked);
        if ($campaign === null) {
            throw StrategyRuleViolation::creatorCampaignRequired();
        }

        return $campaign;
    }

    private function personaOf(Wave $locked, WaveMarket $market, string $ulid): WavePersona
    {
        $persona = WavePersona::query()->where('wave_id', $locked->id)->where('market_id', $market->id)->where('ulid', $ulid)->first();
        if ($persona === null) {
            throw StrategyRuleViolation::creatorPersonaMarketMismatch();
        }

        return $persona;
    }

    /** @param  list<int>  $creatorIds */
    private function assertNotInWave(Wave $locked, array $creatorIds): void
    {
        $existing = WaveCreator::query()->where('wave_id', $locked->id)->whereIn('creator_id', $creatorIds)->first(['market_id']);
        if ($existing !== null) {
            throw StrategyRuleViolation::creatorAlreadyInWave((string) WaveMarket::query()->whereKey($existing->market_id)->value('ulid'));
        }
    }

    /**
     * The first free number of the market whose default code is also free (a code the person edited can hold a default).
     *
     * @param  list<int>  $slots
     * @param  list<string>  $codes
     * @return array{0: int, 1: string}
     */
    private function nextSlot(WaveMarket $market, array $slots, array $codes): array
    {
        for ($n = 1; $n <= 99; $n++) {
            $code = $market->code.'-C'.$n;
            if (! in_array($n, $slots, true) && ! in_array($code, $codes, true)) {
                return [$n, $code];
            }
        }

        throw StrategyRuleViolation::creatorCapReached('market', StrategyLimits::CREATORS_PER_MARKET);
    }
}
