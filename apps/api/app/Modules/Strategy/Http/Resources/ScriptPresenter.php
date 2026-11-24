<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Http\Resources;

use App\Modules\Creators\Models\Creator;
use App\Modules\Strategy\Models\WaveCreator;
use App\Modules\Strategy\Models\WavePersona;
use App\Modules\Strategy\Models\WaveScript;
use App\Modules\Strategy\Models\WaveScriptHook;
use App\Modules\Strategy\Models\WaveScriptHookText;
use App\Modules\Strategy\Notes\NoteService;
use App\Modules\Strategy\Services\ScriptBriefReader;
use App\Modules\Strategy\Services\ScriptEstimate;
use App\Modules\Strategy\Services\WaveScriptStepState;
use App\Modules\Strategy\StrategyLimits;
use App\Modules\Waves\Models\Wave;
use App\Modules\Waves\Models\WaveMarket;
use Illuminate\Database\Eloquent\Collection;

/**
 * The scripts payloads (PL-3.2a). Every read here is a fixed number of queries whatever the number of creators, scripts or
 * hooks (gate: constant queries): the rail is markets, slots with their creators, personas, scripts (with a hook count),
 * archived counts and the notes count; a script is its slot, creator, persona, brief reads, hooks and texts.
 */
final class ScriptPresenter
{
    /**
     * The step's state for the whole wave (the stepper) and, when a market is given, that market's rail.
     *
     * @return array<string, mixed>
     */
    public static function overview(Wave $wave, ?WaveMarket $market): array
    {
        $markets = WaveMarket::query()->where('wave_id', $wave->id)->orderBy('position')->orderBy('id')->get(['id', 'ulid']);
        $live = WaveScript::query()->where('wave_id', $wave->id)->whereNull('archived_at')->selectRaw('market_id, count(*) as n')->groupBy('market_id')->pluck('n', 'market_id')->all();
        $archived = WaveScript::query()->where('wave_id', $wave->id)->whereNotNull('archived_at')->selectRaw('market_id, count(*) as n')->groupBy('market_id')->pluck('n', 'market_id')->all();

        $states = [];
        $rows = [];
        foreach ($markets as $m) {
            $count = (int) ($live[$m->id] ?? 0);
            $state = WaveScriptStepState::market($count);
            $states[] = $state;
            $rows[] = ['id' => $m->ulid, 'state' => $state, 'scripts' => $count, 'archived_scripts' => (int) ($archived[$m->id] ?? 0)];
        }

        return [
            'step' => WaveScriptStepState::wave($states),
            'markets' => $rows,
            'rail' => $market === null ? null : self::rail($wave, $market),
            'limits' => ['scripts_per_creator' => StrategyLimits::SCRIPTS_PER_CREATOR, 'hooks_per_script' => StrategyLimits::HOOKS_PER_SCRIPT],
        ];
    }

    /**
     * The rail of one market: its creators in slot order, each with its scripts.
     *
     * @return array<string, mixed>
     */
    public static function rail(Wave $wave, WaveMarket $market): array
    {
        /** @var Collection<int, WaveCreator> $slots */
        $slots = WaveCreator::query()->where('wave_id', $wave->id)->where('market_id', $market->id)->with('creator')->orderBy('slot')->get();
        $personas = WavePersona::query()->whereIn('id', $slots->pluck('persona_id')->filter()->all())->get()->keyBy('id');

        $creatorIds = $slots->pluck('creator_id')->all();
        $scripts = $creatorIds === [] ? new Collection : WaveScript::query()
            ->where('wave_id', $wave->id)->whereIn('creator_id', $creatorIds)
            ->withCount('hooks')->orderBy('number')->get();
        $notes = NoteService::countForMarketStep($wave, WaveScriptStepState::STEP, $market->id);

        $out = [];
        foreach ($slots as $slot) {
            $mine = $scripts->where('creator_id', $slot->creator_id);
            $live = $mine->where('wave_creator_id', $slot->id)->values();
            $persona = $slot->persona_id === null ? null : $personas->get($slot->persona_id);
            $out[] = [
                'id' => $slot->ulid,
                'slot' => $slot->slot,
                'slot_code' => $slot->slot_code,
                'creator' => self::creator($slot->creator),
                'persona' => $persona === null ? null : ['id' => $persona->ulid, 'code' => $persona->code, 'title' => $persona->title, 'slot' => $persona->slot],
                'scripts' => $live->map(static fn (WaveScript $s): array => [
                    'id' => $s->ulid,
                    'number' => $s->number,
                    'hooks' => (int) $s->getAttribute('hooks_count'),
                    'duration_seconds' => $s->duration_seconds,
                    'revision' => $s->revision,
                    'updated_at' => $s->updated_at->toIso8601String(),
                ])->all(),
                // The cap counts the creator's archived scripts too: they hold their numbers (CHECK and unique key).
                'can_add_script' => $mine->count() < StrategyLimits::SCRIPTS_PER_CREATOR,
            ];
        }

        return [
            'market' => $market->ulid,
            'state' => WaveScriptStepState::market($scripts->whereNotNull('wave_creator_id')->count()),
            'creators' => $out,
            'notes' => $notes,
        ];
    }

    /**
     * One script with its brief, its hooks and the estimate.
     *
     * @return array<string, mixed>
     */
    public static function script(WaveScript $script): array
    {
        $slot = $script->wave_creator_id === null ? null : WaveCreator::query()->whereKey($script->wave_creator_id)->first();
        $hooks = WaveScriptHook::query()->where('script_id', $script->id)->orderBy('position')->orderBy('id')->get();
        $texts = WaveScriptHookText::query()->where('script_id', $script->id)->get()->groupBy('hook_id');

        $hookRows = [];
        $audio = [];
        foreach ($hooks as $hook) {
            $byField = [];
            foreach ($texts->get($hook->id, []) as $t) {
                $byField[$t->field][$t->language] = $t->body;
            }
            $hookRows[] = self::hook($hook, $byField);
            $audio[] = $byField[WaveScriptHookText::AUDIO]['en'] ?? '';
        }

        return [
            'id' => $script->ulid,
            'number' => $script->number,
            'revision' => $script->revision,
            'archived' => $script->isArchived(),
            'market' => (string) WaveMarket::query()->whereKey($script->market_id)->value('ulid'),
            'slot' => $slot === null ? null : ['id' => $slot->ulid, 'slot_code' => $slot->slot_code],
            'creator' => self::creator(Creator::query()->findOrFail($script->creator_id)),
            'brief' => ScriptBriefReader::read($script, $slot?->persona_id),
            'hooks' => $hookRows,
            'estimate' => ScriptEstimate::of($audio),
            'updated_at' => $script->updated_at->toIso8601String(),
        ];
    }

    /**
     * The brief block alone (the `current` of a brief conflict).
     *
     * @return array<string, mixed>
     */
    public static function brief(WaveScript $script): array
    {
        $personaId = $script->wave_creator_id === null ? null : WaveCreator::query()->whereKey($script->wave_creator_id)->value('persona_id');

        return ScriptBriefReader::read($script, $personaId === null ? null : (int) $personaId);
    }

    /**
     * One hook. `$texts` maps field => language => body.
     *
     * @param  array<string, array<string, string>>  $texts
     * @return array<string, mixed>
     */
    public static function hook(WaveScriptHook $hook, array $texts): array
    {
        return [
            'id' => $hook->ulid,
            'position' => $hook->position,
            'letter' => $hook->letter(),
            'shot_type' => $hook->shot_type,
            'visual_description' => $hook->visual_description,
            'texts' => [
                WaveScriptHookText::AUDIO => $texts[WaveScriptHookText::AUDIO] ?? [],
                WaveScriptHookText::OVERLAY => $texts[WaveScriptHookText::OVERLAY] ?? [],
            ],
            'revision' => $hook->revision,
        ];
    }

    /**
     * The archived scripts of one market (Q14): kept until someone deletes them or the creator comes back.
     *
     * @return list<array<string, mixed>>
     */
    public static function archived(Wave $wave, WaveMarket $market): array
    {
        $scripts = WaveScript::query()->where('wave_id', $wave->id)->where('market_id', $market->id)->whereNotNull('archived_at')
            ->with('creator')->withCount('hooks')->orderBy('archived_at')->orderBy('id')->get();
        $inMarket = WaveCreator::query()->where('wave_id', $wave->id)->where('market_id', $market->id)
            ->whereIn('creator_id', $scripts->pluck('creator_id')->all())->pluck('creator_id')->all();

        return array_values($scripts->map(static fn (WaveScript $s): array => [
            'id' => $s->ulid,
            'number' => $s->number,
            'hooks' => (int) $s->getAttribute('hooks_count'),
            'creator' => self::creator($s->creator),
            'archived_at' => $s->archived_at?->toIso8601String(),
            // Archived scripts come back on their own when this creator is added to this market again.
            'creator_in_market' => in_array($s->creator_id, $inMarket, true),
        ])->all());
    }

    /**
     * A creator as the scripts screens need it. An erased creator shows no name ("Deleted creator").
     *
     * @return array<string, mixed>
     */
    public static function creator(Creator $creator): array
    {
        $erased = $creator->isErased();

        return [
            'id' => $creator->ulid,
            'is_erased' => $erased,
            'display_name' => $erased ? null : $creator->display_name,
            'primary_language' => $erased ? null : $creator->primary_language,
            'secondary_languages' => $erased ? [] : array_values($creator->secondary_languages ?? []),
        ];
    }
}
