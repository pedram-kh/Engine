<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Services;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Brands\Models\Brand;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Http\Resources\ScriptPresenter;
use App\Modules\Strategy\Models\WaveScript;
use App\Modules\Strategy\Models\WaveScriptHook;
use App\Modules\Strategy\Models\WaveScriptHookText;
use App\Modules\Strategy\StrategyLimits;
use App\Modules\Waves\Models\Wave;
use App\Modules\Waves\Models\WaveMarket;

/**
 * The hooks of a script (PL-3.2a, PL-3.2b): add, edit, duplicate, delete, reorder. A text can be written in English and in every local
 * language of the market (PL-3.2b); the letter is the position and is never stored. EVERY write goes through {@see ScriptGuard::transaction()}; every change moves the script's
 * `revision`, and an edit carries the hook's own `revision`, so two people on different hooks never conflict and two on the same
 * hook get a 409 with the current hook, never a silent overwrite.
 */
final class ScriptHookService
{
    public const EN = 'en';

    /** A new empty hook at the end. At most six. */
    public function add(Brand $brand, Wave $wave, User $actor, string $scriptUlid): WaveScript
    {
        StrategyGuard::assertBrandWritable($brand);

        return ScriptGuard::transaction($wave, function (Wave $locked) use ($actor, $scriptUlid): WaveScript {
            $script = ScriptGuard::lockedScript($locked, $scriptUlid);
            $count = WaveScriptHook::query()->where('script_id', $script->id)->count();
            if ($count >= StrategyLimits::HOOKS_PER_SCRIPT) {
                throw StrategyRuleViolation::scriptHooksLimit();
            }

            $hook = WaveScriptHook::query()->create(['agency_id' => $locked->agency_id, 'wave_id' => $locked->id, 'script_id' => $script->id, 'position' => $count]);
            $this->touch($script, $actor);
            $this->audit(AuditAction::WaveScriptHookCreated, $locked, $script, $actor, $hook->ulid);

            return $script;
        });
    }

    /**
     * Edit a hook. `$changes` holds only the keys the request sent: `shot_type` (a ShotType value or null), `visual_description`
     * (text or null) and `texts` (`audio` and/or `overlay`, each `{language: text|null}` for English or a language of the market;
     * null or blank removes the row). A save that changes nothing writes nothing. A stale `$revision` with a real change is a 409.
     *
     * @param  array{shot_type?: string|null, visual_description?: string|null, texts?: array<string, array<string, string|null>>}  $changes
     */
    public function update(Brand $brand, Wave $wave, User $actor, string $scriptUlid, string $hookUlid, array $changes, int $revision): WaveScript
    {
        StrategyGuard::assertBrandWritable($brand);

        return ScriptGuard::transaction($wave, function (Wave $locked) use ($actor, $scriptUlid, $hookUlid, $changes, $revision): WaveScript {
            $script = ScriptGuard::lockedScript($locked, $scriptUlid);
            $hook = $this->lockedHook($script, $hookUlid);

            $allowed = ScriptLanguages::allOf($script->market_id);
            foreach ($changes['texts'] ?? [] as $languages) {
                foreach (array_keys($languages) as $language) {
                    if (! in_array((string) $language, $allowed, true)) {
                        throw StrategyRuleViolation::scriptLanguageNotInMarket((string) $language);
                    }
                }
            }

            // Every stored text of the hook, kept ones in a hidden language included (the presenter shows only the market's languages).
            $stored = WaveScriptHookText::query()->where('hook_id', $hook->id)->get();
            $existing = $stored->keyBy(static fn (WaveScriptHookText $t): string => $t->field.':'.$t->language);
            // What really changes, by field name: the scalars, and the body per text field and language (null removes the row).
            $fields = [];
            $scalars = [];
            $bodies = [];
            if (array_key_exists('shot_type', $changes) && $changes['shot_type'] !== $hook->shot_type) {
                $fields[] = 'shot_type';
                $scalars['shot_type'] = $changes['shot_type'];
            }
            if (array_key_exists('visual_description', $changes) && $changes['visual_description'] !== $hook->visual_description) {
                $fields[] = 'visual_description';
                $scalars['visual_description'] = $changes['visual_description'];
            }
            foreach ([WaveScriptHookText::AUDIO, WaveScriptHookText::OVERLAY] as $field) {
                foreach ($changes['texts'][$field] ?? [] as $language => $body) {
                    if ($body !== $existing->get($field.':'.$language)?->body) {
                        // English keeps the PL-3.2a field name (`audio`); another language is `audio:sk`.
                        $fields[] = $language === self::EN ? $field : $field.':'.$language;
                        $bodies[$field.':'.$language] = $body;
                    }
                }
            }
            if ($fields === []) {
                return $script;
            }
            if ($revision !== $hook->revision) {
                throw StrategyRuleViolation::scriptConflict('hook', ScriptPresenter::hookFor($script, $hook));
            }

            if (array_key_exists('shot_type', $scalars)) {
                $hook->shot_type = $scalars['shot_type'];
            }
            if (array_key_exists('visual_description', $scalars)) {
                $hook->visual_description = $scalars['visual_description'];
            }
            foreach ($bodies as $key => $body) {
                [$field, $language] = explode(':', $key, 2);
                $row = $existing->get($key);
                if ($body === null) {
                    $row?->delete();
                } elseif ($row === null) {
                    WaveScriptHookText::query()->create([
                        'agency_id' => $locked->agency_id, 'wave_id' => $locked->id, 'script_id' => $script->id, 'hook_id' => $hook->id,
                        'field' => $field, 'language' => $language, 'body' => $body, 'origin' => 'manual',
                    ]);
                } else {
                    $row->forceFill(['body' => $body])->save();
                }
            }
            $hook->revision++;
            $hook->save();
            $this->touch($script, $actor);
            $this->audit(AuditAction::WaveScriptHookUpdated, $locked, $script, $actor, $hook->ulid, $fields);

            return $script;
        });
    }

    /** Copy a hook (shot type, description, texts) right after the original. At most six hooks. */
    public function duplicate(Brand $brand, Wave $wave, User $actor, string $scriptUlid, string $hookUlid): WaveScript
    {
        StrategyGuard::assertBrandWritable($brand);

        return ScriptGuard::transaction($wave, function (Wave $locked) use ($actor, $scriptUlid, $hookUlid): WaveScript {
            $script = ScriptGuard::lockedScript($locked, $scriptUlid);
            $hook = $this->lockedHook($script, $hookUlid);
            if (WaveScriptHook::query()->where('script_id', $script->id)->count() >= StrategyLimits::HOOKS_PER_SCRIPT) {
                throw StrategyRuleViolation::scriptHooksLimit();
            }

            WaveScriptHook::query()->where('script_id', $script->id)->where('position', '>', $hook->position)->increment('position');
            $copy = WaveScriptHook::query()->create([
                'agency_id' => $locked->agency_id, 'wave_id' => $locked->id, 'script_id' => $script->id, 'position' => $hook->position + 1,
                'shot_type' => $hook->shot_type, 'visual_description' => $hook->visual_description,
            ]);
            foreach (WaveScriptHookText::query()->where('hook_id', $hook->id)->get() as $text) {
                WaveScriptHookText::query()->create([
                    'agency_id' => $locked->agency_id, 'wave_id' => $locked->id, 'script_id' => $script->id, 'hook_id' => $copy->id,
                    'field' => $text->field, 'language' => $text->language, 'body' => $text->body, 'origin' => $text->origin,
                ]);
            }
            ScriptImages::copyBlock($script, $hook->id, null, $copy->id, null, $actor->id);
            $this->touch($script, $actor);
            $this->audit(AuditAction::WaveScriptHookDuplicated, $locked, $script, $actor, $copy->ulid);

            return $script;
        });
    }

    /** Delete a hook and close the gap in the order. The last hook cannot be deleted. */
    public function delete(Brand $brand, Wave $wave, User $actor, string $scriptUlid, string $hookUlid): WaveScript
    {
        StrategyGuard::assertBrandWritable($brand);

        return ScriptGuard::transaction($wave, function (Wave $locked) use ($actor, $scriptUlid, $hookUlid): WaveScript {
            $script = ScriptGuard::lockedScript($locked, $scriptUlid);
            $hook = $this->lockedHook($script, $hookUlid);
            if (WaveScriptHook::query()->where('script_id', $script->id)->count() <= 1) {
                throw StrategyRuleViolation::scriptLastHook();
            }

            // The stored images first (a failed object delete throws here, before a row is touched), then the texts, then the hook.
            ScriptImages::purgeBlock($script->id, $hook->id, null);
            WaveScriptHookText::query()->where('hook_id', $hook->id)->delete();
            $hook->delete();
            WaveScriptHook::query()->where('script_id', $script->id)->where('position', '>', $hook->position)->decrement('position');
            $this->touch($script, $actor);
            $this->audit(AuditAction::WaveScriptHookDeleted, $locked, $script, $actor, $hook->ulid);

            return $script;
        });
    }

    /**
     * Reorder the hooks. `$ids` must be exactly the hooks that exist now (the complete list), otherwise `order_mismatch` with the
     * current order and nothing is written. No hook's revision moves; the script's does. The letters follow the new positions.
     *
     * @param  list<string>  $ids
     */
    public function reorder(Brand $brand, Wave $wave, User $actor, string $scriptUlid, array $ids): WaveScript
    {
        StrategyGuard::assertBrandWritable($brand);

        return ScriptGuard::transaction($wave, function (Wave $locked) use ($actor, $scriptUlid, $ids): WaveScript {
            $script = ScriptGuard::lockedScript($locked, $scriptUlid);
            $hooks = WaveScriptHook::query()->where('script_id', $script->id)->orderBy('position')->orderBy('id')->lockForUpdate()->get();
            $current = array_values($hooks->map(static fn ($m): string => (string) $m->ulid)->all());
            if (! ScriptOrder::sameSet($current, $ids)) {
                throw StrategyRuleViolation::scriptOrderMismatch('hooks', $current);
            }
            $byUlid = $hooks->keyBy('ulid');
            foreach ($ids as $position => $ulid) {
                $hook = $byUlid->get($ulid);
                if ($hook !== null && $hook->position !== $position) {
                    // `position` only: the hook's revision is deliberately NOT moved.
                    WaveScriptHook::query()->whereKey($hook->id)->update(['position' => $position]);
                }
            }
            $this->touch($script, $actor);
            Audit::log(
                action: AuditAction::WaveScriptHookReordered,
                actor: $actor,
                subject: $script,
                metadata: ['wave' => $locked->ulid, 'market' => ScriptGuard::marketUlid($script), 'script' => $script->ulid, 'number' => $script->number, 'count' => count($ids)],
                agencyId: $locked->agency_id,
            );

            return $script;
        });
    }

    private function lockedHook(WaveScript $script, string $ulid): WaveScriptHook
    {
        $hook = WaveScriptHook::query()->where('script_id', $script->id)->where('ulid', $ulid)->lockForUpdate()->first();
        if ($hook === null) {
            throw StrategyRuleViolation::conflict404();
        }

        return $hook;
    }

    private function touch(WaveScript $script, User $actor): void
    {
        $script->revision++;
        $script->updated_by_user_id = $actor->id;
        $script->save();
    }

    /** @param  array<int, string>|null  $fields  the changed FIELD NAMES of an edit, null for the other actions */
    private function audit(AuditAction $action, Wave $locked, WaveScript $script, User $actor, string $hook, ?array $fields = null): void
    {
        $market = (string) WaveMarket::query()->whereKey($script->market_id)->value('ulid');
        Audit::log(
            action: $action,
            actor: $actor,
            subject: $script,
            // Literal keys on purpose (AuditPayloadKeysGuardTest reads them statically). Ids, the number and field names: never a text.
            metadata: ['wave' => $locked->ulid, 'market' => $market, 'script' => $script->ulid, 'number' => $script->number, 'hook' => $hook, 'fields' => $fields],
            agencyId: $locked->agency_id,
        );
    }
}
