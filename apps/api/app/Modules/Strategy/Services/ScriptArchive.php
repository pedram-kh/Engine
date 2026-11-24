<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Services;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Models\WaveCreator;
use App\Modules\Strategy\Models\WaveScript;
use App\Modules\Strategy\Models\WaveScriptHook;
use App\Modules\Strategy\Models\WaveScriptHookText;
use App\Modules\Strategy\StrategyLimits;
use App\Modules\Waves\Models\Wave;
use App\Modules\Waves\Models\WaveMarket;
use Illuminate\Database\Eloquent\Collection;

/**
 * What happens to a slot's scripts when the slot changes (PL-3.2a, ruling Q8): archive, restore, delete, carry over. Called
 * ONLY from inside a {@see ScriptGuard::transaction()} (or {@see WaveCreatorGuard::transaction()}, the same wave lock), so it
 * never opens a transaction of its own. Audit rows: ids, the number, counts; never a script's text.
 */
final class ScriptArchive
{
    /**
     * The live scripts of a slot, locked, in number order.
     *
     * @return Collection<int, WaveScript>
     */
    public static function liveOf(WaveCreator $slot): Collection
    {
        return WaveScript::query()->where('wave_id', $slot->wave_id)->where('wave_creator_id', $slot->id)->orderBy('number')->lockForUpdate()->get();
    }

    /** Archive the slot's live scripts: no slot, `archived_at` set, hooks and texts untouched. Returns how many. */
    public static function archive(Wave $locked, WaveCreator $slot, WaveMarket $market, User $actor): int
    {
        $scripts = self::liveOf($slot);
        foreach ($scripts as $script) {
            $script->forceFill(['wave_creator_id' => null, 'archived_at' => now(), 'revision' => $script->revision + 1, 'updated_by_user_id' => $actor->id])->save();
            Audit::log(
                action: AuditAction::WaveScriptArchived,
                actor: $actor,
                subject: $script,
                metadata: ['wave' => $locked->ulid, 'market' => $market->ulid, 'script' => $script->ulid, 'number' => $script->number, 'creator_id' => $script->creator_id],
                agencyId: $locked->agency_id,
            );
        }

        return $scripts->count();
    }

    /**
     * Restore the archived scripts of the slot's creator in the slot's market of this wave onto the slot (a creator who is added
     * again, or the new creator of a Replace). Scripts archived in another market stay archived. Returns how many.
     */
    public static function restore(Wave $locked, WaveCreator $slot, WaveMarket $market, User $actor): int
    {
        $scripts = WaveScript::query()
            ->where('wave_id', $locked->id)->where('market_id', $slot->market_id)->where('creator_id', $slot->creator_id)
            ->whereNotNull('archived_at')->orderBy('number')->lockForUpdate()->get();
        foreach ($scripts as $script) {
            $script->forceFill(['wave_creator_id' => $slot->id, 'archived_at' => null, 'revision' => $script->revision + 1, 'updated_by_user_id' => $actor->id])->save();
            Audit::log(
                action: AuditAction::WaveScriptRestored,
                actor: $actor,
                subject: $script,
                metadata: ['wave' => $locked->ulid, 'market' => $market->ulid, 'script' => $script->ulid, 'number' => $script->number, 'creator_id' => $script->creator_id],
                agencyId: $locked->agency_id,
            );
        }

        return $scripts->count();
    }

    /** Delete the slot's live scripts with their hooks and texts (Remove with "delete"). Returns how many scripts. */
    public static function deleteLive(Wave $locked, WaveCreator $slot, WaveMarket $market, User $actor): int
    {
        $scripts = self::liveOf($slot);
        foreach ($scripts as $script) {
            self::deleteOne($locked, $script, $market->ulid, $actor);
        }

        return $scripts->count();
    }

    /** Delete one script, its hooks and its texts, and write the audit row (ids and counts). */
    public static function deleteOne(Wave $locked, WaveScript $script, string $marketUlid, ?User $actor): void
    {
        $hooks = WaveScriptHook::query()->where('script_id', $script->id)->count();
        WaveScriptHookText::query()->where('script_id', $script->id)->delete();
        WaveScriptHook::query()->where('script_id', $script->id)->delete();
        $script->delete();

        Audit::log(
            action: AuditAction::WaveScriptDeleted,
            actor: $actor,
            subject: $script,
            metadata: ['wave' => $locked->ulid, 'market' => $marketUlid, 'script' => $script->ulid, 'number' => $script->number, 'creator_id' => $script->creator_id, 'hooks' => $hooks],
            agencyId: $locked->agency_id,
        );
    }

    /**
     * Renumber the scripts a Replace carries to a new creator so none takes a number the new creator already holds (an archived
     * script of theirs in this wave). The scripts keep their order. The total must stay within the cap, or nothing is written.
     *
     * @param  Collection<int, WaveScript>  $carried  the outgoing creator's live scripts (locked)
     */
    public static function renumberForCarryOver(Wave $locked, Collection $carried, int $newCreatorId): void
    {
        $held = WaveScript::query()->where('wave_id', $locked->id)->where('creator_id', $newCreatorId)->lockForUpdate()->pluck('number')->map(static fn (mixed $n): int => (int) $n)->all();
        $total = $carried->count() + count($held);
        if ($total > StrategyLimits::SCRIPTS_PER_CREATOR) {
            throw StrategyRuleViolation::scriptCarryOverExceedsLimit($total);
        }

        // A moved script must also not take a number the OUTGOING creator still holds on a script that stays behind (archived).
        $stays = WaveScript::query()->where('wave_id', $locked->id)->whereIn('creator_id', $carried->pluck('creator_id')->unique()->all())
            ->whereNotIn('id', $carried->pluck('id')->all())->pluck('number')->map(static fn (mixed $n): int => (int) $n)->all();
        $keep = $carried->pluck('number')->map(static fn (mixed $n): int => (int) $n)->diff($held)->values()->all();
        $taken = [...$held, ...$keep, ...$stays];
        foreach ($carried as $script) {
            if (! in_array($script->number, $held, true)) {
                continue;
            }
            for ($n = 1; $n <= StrategyLimits::SCRIPTS_PER_CREATOR; $n++) {
                if (! in_array($n, $taken, true)) {
                    $script->forceFill(['number' => $n])->save();
                    $taken[] = $n;
                    break;
                }
            }
        }
    }
}
