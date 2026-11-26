<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Services;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Models\WaveCreator;
use App\Modules\Strategy\Models\WaveScript;
use App\Modules\Strategy\Models\WaveScriptBroll;
use App\Modules\Strategy\Models\WaveScriptHook;
use App\Modules\Strategy\Models\WaveScriptHookText;
use App\Modules\Strategy\Models\WaveScriptLine;
use App\Modules\Strategy\Models\WaveScriptLineText;
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
            self::archiveOne($locked, $script, $market->ulid, $actor);
        }

        return $scripts->count();
    }

    /** Archive ONE live script (the editor's "Archive script"): hooks and texts untouched, its number is free for a new script. */
    public static function archiveOne(Wave $locked, WaveScript $script, string $marketUlid, User $actor): void
    {
        $script->forceFill(['wave_creator_id' => null, 'archived_at' => now(), 'revision' => $script->revision + 1, 'updated_by_user_id' => $actor->id])->save();
        Audit::log(
            action: AuditAction::WaveScriptArchived,
            actor: $actor,
            subject: $script,
            metadata: ['wave' => $locked->ulid, 'market' => $marketUlid, 'script' => $script->ulid, 'number' => $script->number, 'creator_id' => $script->creator_id],
            agencyId: $locked->agency_id,
        );
    }

    /**
     * The numbers the creator's LIVE scripts hold in this wave, locked. Archived scripts hold none (a partial unique index).
     *
     * @return list<int>
     */
    public static function liveNumbers(Wave $locked, int $creatorId): array
    {
        return array_values(WaveScript::query()->where('wave_id', $locked->id)->where('creator_id', $creatorId)->whereNull('archived_at')
            ->lockForUpdate()->pluck('number')->map(static fn (mixed $n): int => (int) $n)->all());
    }

    /**
     * The number a script comes back with: its own when that is free among the live ones, otherwise the lowest free one; null when
     * the creator already has all three.
     *
     * @param  list<int>  $taken
     */
    public static function numberFor(int $own, array $taken): ?int
    {
        if (! in_array($own, $taken, true) && $own >= 1 && $own <= StrategyLimits::SCRIPTS_PER_CREATOR) {
            return $own;
        }
        for ($n = 1; $n <= StrategyLimits::SCRIPTS_PER_CREATOR; $n++) {
            if (! in_array($n, $taken, true)) {
                return $n;
            }
        }

        return null;
    }

    /**
     * Restore the archived scripts of the slot's creator in the slot's market of this wave onto the slot (a creator who is added
     * again, or the new creator of a Replace), as many as fit under the three-live cap, in number order. The rest stay archived.
     * Scripts archived in another market stay archived. Returns how many came back.
     */
    public static function restore(Wave $locked, WaveCreator $slot, WaveMarket $market, User $actor): int
    {
        $scripts = WaveScript::query()
            ->where('wave_id', $locked->id)->where('market_id', $slot->market_id)->where('creator_id', $slot->creator_id)
            ->whereNotNull('archived_at')->orderBy('number')->orderBy('id')->lockForUpdate()->get();
        $taken = $scripts->isEmpty() ? [] : self::liveNumbers($locked, $slot->creator_id);
        $restored = 0;
        foreach ($scripts as $script) {
            $number = self::numberFor($script->number, $taken);
            if ($number === null) {
                break;
            }
            self::bringBack($locked, $script, $slot, $market->ulid, $number, $actor);
            $taken[] = $number;
            $restored++;
        }

        return $restored;
    }

    /**
     * Restore ONE archived script (the archived list's "Restore"). The creator must still hold a slot in the script's market, and
     * must have fewer than three live scripts; otherwise nothing is written. The script keeps its number when it is free, else
     * it takes the lowest free one.
     */
    public static function restoreOne(Wave $locked, WaveScript $script, User $actor): void
    {
        $slot = WaveCreator::query()->where('wave_id', $locked->id)->where('market_id', $script->market_id)->where('creator_id', $script->creator_id)->lockForUpdate()->first();
        if ($slot === null) {
            throw StrategyRuleViolation::scriptRestoreCreatorMissing();
        }
        $number = self::numberFor($script->number, self::liveNumbers($locked, $script->creator_id));
        if ($number === null) {
            throw StrategyRuleViolation::scriptRestoreLimitReached();
        }
        self::bringBack($locked, $script, $slot, (string) WaveMarket::query()->whereKey($script->market_id)->value('ulid'), $number, $actor);
    }

    /** Delete the slot's live scripts with everything on them, stored images included (Remove with "delete"). Returns how many scripts. */
    public static function deleteLive(Wave $locked, WaveCreator $slot, WaveMarket $market, User $actor): int
    {
        $scripts = self::liveOf($slot);
        foreach ($scripts as $script) {
            self::deleteOne($locked, $script, $market->ulid, $actor);
        }

        return $scripts->count();
    }

    /**
     * Delete one script with everything that hangs on it, and write the audit row (ids and counts). The stored image OBJECTS go
     * first: a failed delete throws before a single row is touched (the caller's transaction rolls back and the script stays), and
     * the script row lock held by every caller means no image can be reserved between the two steps (PL-3.2b, ruling 11). Then the
     * rows, children before parents (the foreign keys are RESTRICT).
     */
    public static function deleteOne(Wave $locked, WaveScript $script, string $marketUlid, ?User $actor): void
    {
        $hooks = WaveScriptHook::query()->where('script_id', $script->id)->count();
        $lines = WaveScriptLine::query()->where('script_id', $script->id)->count();
        $images = ScriptImages::purgeScript($script->id);
        WaveScriptBroll::query()->where('script_id', $script->id)->delete();
        WaveScriptLineText::query()->where('script_id', $script->id)->delete();
        WaveScriptLine::query()->where('script_id', $script->id)->delete();
        WaveScriptHookText::query()->where('script_id', $script->id)->delete();
        WaveScriptHook::query()->where('script_id', $script->id)->delete();
        $script->delete();

        Audit::log(
            action: AuditAction::WaveScriptDeleted,
            actor: $actor,
            subject: $script,
            metadata: ['wave' => $locked->ulid, 'market' => $marketUlid, 'script' => $script->ulid, 'number' => $script->number, 'creator_id' => $script->creator_id, 'hooks' => $hooks, 'lines' => $lines, 'images' => $images],
            agencyId: $locked->agency_id,
        );
    }

    private static function bringBack(Wave $locked, WaveScript $script, WaveCreator $slot, string $marketUlid, int $number, User $actor): void
    {
        $script->forceFill(['wave_creator_id' => $slot->id, 'archived_at' => null, 'number' => $number, 'revision' => $script->revision + 1, 'updated_by_user_id' => $actor->id])->save();
        Audit::log(
            action: AuditAction::WaveScriptRestored,
            actor: $actor,
            subject: $script,
            metadata: ['wave' => $locked->ulid, 'market' => $marketUlid, 'script' => $script->ulid, 'number' => $number, 'creator_id' => $script->creator_id],
            agencyId: $locked->agency_id,
        );
    }
}
