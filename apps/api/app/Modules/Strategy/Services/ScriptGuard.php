<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Models\WaveScript;
use App\Modules\Waves\Models\Wave;
use App\Modules\Waves\Models\WaveMarket;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * THE choke point of every script write (PL-3.2a, as {@see WaveCreatorGuard} is for slots). The order is fixed and is the
 * lock order everywhere: the WAVE row first, then (when the write is about one script) the SCRIPT row, then the re-check of
 * `archived_at`. A script writer never opens its own `DB::transaction(`: a source-string test reads the writers and refuses it.
 *
 * Why the wave lock first: the cap of three per creator, the slot (a Remove or Replace archives or moves scripts), the market
 * guard (a market removal deletes scripts) and the carry-over all read or write across scripts of one wave. One lock order, no
 * deadlock.
 */
final class ScriptGuard
{
    /**
     * Run `$change` in a transaction that holds the wave row lock; it receives the locked wave.
     *
     * @param  Closure(Wave): mixed  $change
     */
    public static function transaction(Wave $wave, Closure $change): mixed
    {
        return DB::transaction(static function () use ($wave, $change): mixed {
            return $change(StrategyGuard::lockWave($wave));
        });
    }

    /**
     * A LIVE script of the wave by ulid, locked FOR UPDATE (call it after the wave lock). A script of another wave or agency,
     * an unknown ulid and an ARCHIVED script are all one 404: an archived script is not edited, it is restored or deleted.
     */
    public static function lockedScript(Wave $locked, string $ulid): WaveScript
    {
        $script = WaveScript::query()->where('wave_id', $locked->id)->where('ulid', $ulid)->lockForUpdate()->first();
        if ($script === null || $script->archived_at !== null || $script->wave_creator_id === null) {
            throw StrategyRuleViolation::conflict404();
        }

        return $script;
    }

    /** An ARCHIVED script of the wave by ulid, locked FOR UPDATE (the archived list's delete). A live script is a 404 here. */
    public static function lockedArchivedScript(Wave $locked, string $ulid): WaveScript
    {
        $script = WaveScript::query()->where('wave_id', $locked->id)->where('ulid', $ulid)->lockForUpdate()->first();
        if ($script === null || $script->archived_at === null) {
            throw StrategyRuleViolation::conflict404();
        }

        return $script;
    }

    /** Move the script's `revision` (every change of the script or of any block of it) and record who. Call inside the guard. */
    public static function touch(WaveScript $script, User $actor): void
    {
        $script->revision++;
        $script->updated_by_user_id = $actor->id;
        $script->save();
    }

    /** The ulid of the script's market (audit payloads carry it). */
    public static function marketUlid(WaveScript $script): string
    {
        return (string) WaveMarket::query()->whereKey($script->market_id)->value('ulid');
    }
}
