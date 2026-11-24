<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Services;

use App\Core\Tenancy\CurrentAgencyRole;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Brands\Models\Brand;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Http\Resources\ScriptPresenter;
use App\Modules\Strategy\Models\WaveCreator;
use App\Modules\Strategy\Models\WaveScript;
use App\Modules\Strategy\Models\WaveScriptHook;
use App\Modules\Strategy\StrategyLimits;
use App\Modules\Waves\Models\Wave;
use App\Modules\Waves\Models\WaveMarket;

/**
 * Create, edit the brief of, and delete scripts (PL-3.2a). EVERY write goes through {@see ScriptGuard::transaction()}: the wave
 * row lock first, then the script row lock, then the checks. Archive, restore and carry-over live in {@see ScriptArchive} and
 * are called by the creators service inside its own guarded transaction. Audit rows have literal keys; never the angle.
 */
final class ScriptService
{
    /** A new script for a slot: the lowest free number 1 to 3 (archived scripts of the creator hold theirs), hooks A, B and C. */
    public function create(Brand $brand, Wave $wave, User $actor, string $slotUlid): WaveScript
    {
        StrategyGuard::assertBrandWritable($brand);

        return ScriptGuard::transaction($wave, function (Wave $locked) use ($actor, $slotUlid): WaveScript {
            $slot = WaveCreator::query()->where('wave_id', $locked->id)->where('ulid', $slotUlid)->lockForUpdate()->first();
            if ($slot === null) {
                throw StrategyRuleViolation::conflict404();
            }
            $market = WaveMarket::query()->findOrFail($slot->market_id);

            $held = WaveScript::query()->where('wave_id', $locked->id)->where('creator_id', $slot->creator_id)->get(['number', 'archived_at']);
            $number = null;
            for ($n = 1; $n <= StrategyLimits::SCRIPTS_PER_CREATOR; $n++) {
                if (! $held->contains('number', $n)) {
                    $number = $n;
                    break;
                }
            }
            if ($number === null) {
                throw StrategyRuleViolation::scriptLimitReached($held->whereNotNull('archived_at')->count());
            }

            $script = WaveScript::query()->create([
                'agency_id' => $locked->agency_id,
                'wave_id' => $locked->id,
                'market_id' => $slot->market_id,
                'creator_id' => $slot->creator_id,
                'wave_creator_id' => $slot->id,
                'number' => $number,
                'duration_seconds' => StrategyLimits::SCRIPT_DURATION_DEFAULT,
                'revision' => 1,
                'brief_revision' => 1,
                'created_by_user_id' => $actor->id,
                'updated_by_user_id' => $actor->id,
            ]);
            for ($i = 0; $i < StrategyLimits::HOOKS_ON_CREATE; $i++) {
                WaveScriptHook::query()->create(['agency_id' => $locked->agency_id, 'wave_id' => $locked->id, 'script_id' => $script->id, 'position' => $i]);
            }

            Audit::log(
                action: AuditAction::WaveScriptCreated,
                actor: $actor,
                subject: $script,
                metadata: ['wave' => $locked->ulid, 'market' => $market->ulid, 'script' => $script->ulid, 'number' => $number, 'creator_id' => $slot->creator_id, 'hooks' => StrategyLimits::HOOKS_ON_CREATE],
                agencyId: $locked->agency_id,
            );

            return $script;
        });
    }

    /**
     * Save the brief: the angle (a text, or null to go back to the live persona angle) and/or the duration. `$changes` holds only
     * the keys the request sent. A save that changes nothing writes nothing. With a stale `$revision` and a real change: 409.
     *
     * @param  array{angle?: string|null, duration_seconds?: int}  $changes
     */
    public function updateBrief(Brand $brand, Wave $wave, User $actor, string $scriptUlid, array $changes, int $revision): WaveScript
    {
        StrategyGuard::assertBrandWritable($brand);

        return ScriptGuard::transaction($wave, function (Wave $locked) use ($actor, $scriptUlid, $changes, $revision): WaveScript {
            $script = ScriptGuard::lockedScript($locked, $scriptUlid);

            $fields = [];
            if (array_key_exists('angle', $changes) && $changes['angle'] !== $script->angle) {
                $fields[] = 'angle';
            }
            if (array_key_exists('duration_seconds', $changes) && $changes['duration_seconds'] !== $script->duration_seconds) {
                $fields[] = 'duration_seconds';
            }
            if ($fields === []) {
                return $script;
            }
            if ($revision !== $script->brief_revision) {
                throw StrategyRuleViolation::scriptConflict('brief', ScriptPresenter::brief($script));
            }

            if (in_array('angle', $fields, true)) {
                $script->angle = $changes['angle'];
            }
            if (in_array('duration_seconds', $fields, true)) {
                $script->duration_seconds = (int) $changes['duration_seconds'];
            }
            $script->brief_revision++;
            $script->revision++;
            $script->updated_by_user_id = $actor->id;
            $script->save();

            Audit::log(
                action: AuditAction::WaveScriptUpdated,
                actor: $actor,
                subject: $script,
                metadata: ['wave' => $locked->ulid, 'market' => $this->marketUlid($script), 'script' => $script->ulid, 'number' => $script->number, 'fields' => $fields],
                agencyId: $locked->agency_id,
            );

            return $script;
        });
    }

    /** Delete a LIVE script for good: its hooks and texts go with it, and its number becomes free. */
    public function delete(Brand $brand, Wave $wave, User $actor, string $scriptUlid): void
    {
        StrategyGuard::assertBrandWritable($brand);

        ScriptGuard::transaction($wave, function (Wave $locked) use ($actor, $scriptUlid): void {
            $script = ScriptGuard::lockedScript($locked, $scriptUlid);
            ScriptArchive::deleteOne($locked, $script, $this->marketUlid($script), $actor);
        });
    }

    /**
     * Delete an ARCHIVED script for good (the archived list's "Delete"). Admin and manager only: it is the one way a script's
     * text is destroyed without the person who wrote it, so it is a manage action like deleting a USP that is in use.
     */
    public function deleteArchived(Brand $brand, Wave $wave, User $actor, string $scriptUlid): void
    {
        StrategyGuard::assertBrandWritable($brand);
        if (! CurrentAgencyRole::canManage($actor)) {
            throw StrategyRuleViolation::scriptsDeleteForbidden();
        }

        ScriptGuard::transaction($wave, function (Wave $locked) use ($actor, $scriptUlid): void {
            $script = ScriptGuard::lockedArchivedScript($locked, $scriptUlid);
            ScriptArchive::deleteOne($locked, $script, $this->marketUlid($script), $actor);
        });
    }

    private function marketUlid(WaveScript $script): string
    {
        return (string) WaveMarket::query()->whereKey($script->market_id)->value('ulid');
    }
}
