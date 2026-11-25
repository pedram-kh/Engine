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
 * Create, edit the brief of, archive, restore and (from the archive, admin and manager only) delete scripts (PL-3.2a). EVERY write goes through {@see ScriptGuard::transaction()}: the wave
 * row lock first, then the script row lock, then the checks. Archive, restore and carry-over live in {@see ScriptArchive} and
 * are called by the creators service inside its own guarded transaction. Audit rows have literal keys; never the angle.
 */
final class ScriptService
{
    /** A new script for a slot: the lowest free number 1 to 3 among the creator's LIVE scripts, hooks A, B and C. */
    public function create(Brand $brand, Wave $wave, User $actor, string $slotUlid): WaveScript
    {
        StrategyGuard::assertBrandWritable($brand);

        return ScriptGuard::transaction($wave, function (Wave $locked) use ($actor, $slotUlid): WaveScript {
            $slot = WaveCreator::query()->where('wave_id', $locked->id)->where('ulid', $slotUlid)->lockForUpdate()->first();
            if ($slot === null) {
                throw StrategyRuleViolation::conflict404();
            }
            $market = WaveMarket::query()->findOrFail($slot->market_id);

            // Only LIVE scripts hold a number and count toward the three: an archived one has given its place up.
            $number = ScriptArchive::numberFor(1, ScriptArchive::liveNumbers($locked, $slot->creator_id));
            if ($number === null) {
                throw StrategyRuleViolation::scriptLimitReached();
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
            $angle = null;
            $duration = null;
            if (array_key_exists('angle', $changes) && $changes['angle'] !== $script->angle) {
                $fields[] = 'angle';
                $angle = $changes['angle'];
            }
            if (array_key_exists('duration_seconds', $changes) && $changes['duration_seconds'] !== $script->duration_seconds) {
                $fields[] = 'duration_seconds';
                $duration = $changes['duration_seconds'];
            }
            if ($fields === []) {
                return $script;
            }
            if ($revision !== $script->brief_revision) {
                throw StrategyRuleViolation::scriptConflict('brief', ScriptPresenter::brief($script));
            }

            if (in_array('angle', $fields, true)) {
                $script->angle = $angle;
            }
            if ($duration !== null) {
                $script->duration_seconds = $duration;
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

    /**
     * Archive a LIVE script (the editor's "Archive script", every agency role): it leaves the rail for the archived list with its
     * hooks and texts untouched, and its place (the number) is free for a new script. Nothing is destroyed.
     */
    public function archive(Brand $brand, Wave $wave, User $actor, string $scriptUlid): void
    {
        StrategyGuard::assertBrandWritable($brand);

        ScriptGuard::transaction($wave, function (Wave $locked) use ($actor, $scriptUlid): void {
            $script = ScriptGuard::lockedScript($locked, $scriptUlid);
            ScriptArchive::archiveOne($locked, $script, $this->marketUlid($script), $actor);
        });
    }

    /**
     * Restore an ARCHIVED script (every agency role): back on its creator's slot, with its own number when free, otherwise the
     * lowest free one. Refused, writing nothing, when the creator already has three live scripts or is no longer in the market.
     */
    public function restore(Brand $brand, Wave $wave, User $actor, string $scriptUlid): void
    {
        StrategyGuard::assertBrandWritable($brand);

        ScriptGuard::transaction($wave, function (Wave $locked) use ($actor, $scriptUlid): void {
            ScriptArchive::restoreOne($locked, ScriptGuard::lockedArchivedScript($locked, $scriptUlid), $actor);
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
