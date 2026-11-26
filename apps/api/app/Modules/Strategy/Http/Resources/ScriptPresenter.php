<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Http\Resources;

use App\Modules\Creators\Models\Creator;
use App\Modules\Strategy\Models\WaveCreator;
use App\Modules\Strategy\Models\WavePersona;
use App\Modules\Strategy\Models\WaveScript;
use App\Modules\Strategy\Models\WaveScriptBroll;
use App\Modules\Strategy\Models\WaveScriptHook;
use App\Modules\Strategy\Models\WaveScriptHookText;
use App\Modules\Strategy\Models\WaveScriptImage;
use App\Modules\Strategy\Models\WaveScriptLine;
use App\Modules\Strategy\Models\WaveScriptLineText;
use App\Modules\Strategy\Notes\NoteService;
use App\Modules\Strategy\Services\ScriptBriefReader;
use App\Modules\Strategy\Services\ScriptCodes;
use App\Modules\Strategy\Services\ScriptEstimate;
use App\Modules\Strategy\Services\ScriptLanguages;
use App\Modules\Strategy\Services\WaveScriptStepState;
use App\Modules\Strategy\StrategyLimits;
use App\Modules\Waves\Models\Wave;
use App\Modules\Waves\Models\WaveMarket;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

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
            'limits' => self::limits(),
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
                'persona' => $persona === null ? null : ['id' => $persona->ulid, 'code' => $persona->code, 'title' => $persona->title, 'slot' => $persona->slot, 'color_key' => $persona->colorKey()->value],
                'scripts' => $live->map(static fn (WaveScript $s): array => [
                    'id' => $s->ulid,
                    'number' => $s->number,
                    'hooks' => (int) $s->getAttribute('hooks_count'),
                    'duration_seconds' => $s->duration_seconds,
                    'revision' => $s->revision,
                    'updated_at' => $s->updated_at->toIso8601String(),
                ])->all(),
                // The cap of three counts LIVE scripts only: archiving one frees its place.
                'can_add_script' => $live->count() < StrategyLimits::SCRIPTS_PER_CREATOR,
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
     * The limits the screens show (near the limit only), one place for the script and the overview.
     *
     * @return array<string, int>
     */
    public static function limits(): array
    {
        return [
            'scripts_per_creator' => StrategyLimits::SCRIPTS_PER_CREATOR,
            'hooks_per_script' => StrategyLimits::HOOKS_PER_SCRIPT,
            'lines_per_script' => StrategyLimits::LINES_PER_SCRIPT,
            'broll_per_script' => StrategyLimits::BROLL_PER_SCRIPT,
            'images_per_block' => StrategyLimits::IMAGES_PER_BLOCK,
            'image_total_bytes' => StrategyLimits::SCRIPT_IMAGE_TOTAL_BYTES,
            'local_languages_per_market' => StrategyLimits::LOCAL_LANGUAGES_PER_MARKET,
        ];
    }

    /**
     * One script with its brief, its hooks, its lines (texts by language, B-roll with computed codes, images), the market's
     * languages, the limits and the estimate. A FIXED number of queries whatever the number of hooks, lines, rows or images.
     *
     * @return array<string, mixed>
     */
    public static function script(WaveScript $script): array
    {
        $slot = $script->wave_creator_id === null ? null : WaveCreator::query()->whereKey($script->wave_creator_id)->first();
        $local = ScriptLanguages::localOf($script->market_id);
        $languages = [ScriptLanguages::ENGLISH, ...$local];
        $base = self::scriptBase($script);

        $hooks = WaveScriptHook::query()->where('script_id', $script->id)->orderBy('position')->orderBy('id')->get();
        $hookTexts = WaveScriptHookText::query()->where('script_id', $script->id)->whereIn('language', $languages)->get()->groupBy('hook_id');
        $images = WaveScriptImage::query()->where('script_id', $script->id)->where('status', WaveScriptImage::READY)->orderBy('position')->orderBy('id')->get();
        $hookImages = $images->whereNotNull('hook_id')->groupBy('hook_id');
        $lineImages = $images->whereNotNull('line_id')->groupBy('line_id');

        $hookRows = [];
        $audio = [];
        foreach ($hooks as $hook) {
            $byField = [];
            foreach ($hookTexts->get($hook->id, []) as $t) {
                $byField[$t->field][$t->language] = $t->body;
            }
            $hookRows[] = self::hook($hook, $byField, self::images($hookImages->get($hook->id, []), $base));
            $audio[] = ScriptLanguages::pick($byField[WaveScriptHookText::AUDIO] ?? [], $local);
        }

        $lines = WaveScriptLine::query()->where('script_id', $script->id)->orderBy('position')->orderBy('id')->get();
        $lineTexts = WaveScriptLineText::query()->where('script_id', $script->id)->whereIn('language', $languages)->get()->groupBy('line_id');
        $broll = WaveScriptBroll::query()->where('script_id', $script->id)->orderBy('position')->orderBy('id')->get()->groupBy('line_id');
        $lineRows = [];
        $lineSpoken = [];
        foreach ($lines as $line) {
            $byLanguage = [];
            foreach ($lineTexts->get($line->id, []) as $t) {
                $byLanguage[$t->language] = $t->body;
            }
            $lineRows[] = self::line($line, $byLanguage, $broll->get($line->id, []), self::images($lineImages->get($line->id, []), $base));
            $lineSpoken[] = ScriptLanguages::pick($byLanguage, $local);
        }

        return [
            'id' => $script->ulid,
            'number' => $script->number,
            'revision' => $script->revision,
            'archived' => $script->isArchived(),
            'market' => (string) WaveMarket::query()->whereKey($script->market_id)->value('ulid'),
            'languages' => $languages,
            'slot' => $slot === null ? null : ['id' => $slot->ulid, 'slot_code' => $slot->slot_code],
            'creator' => self::creator(Creator::query()->findOrFail($script->creator_id)),
            'brief' => ScriptBriefReader::read($script, $slot?->persona_id),
            'hooks' => $hookRows,
            'lines' => $lineRows,
            'usage' => [
                'hooks' => count($hookRows),
                'lines' => count($lineRows),
                'broll' => (int) $broll->sum(static fn ($rows): int => count($rows)),
                'image_bytes' => (int) $images->sum('size_bytes'),
            ],
            'limits' => self::limits(),
            'estimate' => ScriptEstimate::of($audio, $lineSpoken),
            'updated_at' => $script->updated_at->toIso8601String(),
        ];
    }

    /**
     * One line of a script on its own (the `current` of a line conflict or a `broll_gone`).
     *
     * @return array<string, mixed>
     */
    public static function lineFor(WaveScript $script, WaveScriptLine $line): array
    {
        $languages = ScriptLanguages::allOf($script->market_id);
        $byLanguage = [];
        foreach (WaveScriptLineText::query()->where('line_id', $line->id)->whereIn('language', $languages)->get() as $t) {
            $byLanguage[$t->language] = $t->body;
        }
        $images = WaveScriptImage::query()->where('line_id', $line->id)->where('status', WaveScriptImage::READY)->orderBy('position')->orderBy('id')->get();

        return self::line($line, $byLanguage, WaveScriptBroll::query()->where('line_id', $line->id)->orderBy('position')->orderBy('id')->get(), self::images($images, self::scriptBase($script)));
    }

    /**
     * One hook of a script on its own (the `current` of a hook conflict).
     *
     * @return array<string, mixed>
     */
    public static function hookFor(WaveScript $script, WaveScriptHook $hook): array
    {
        $languages = ScriptLanguages::allOf($script->market_id);
        $byField = [];
        foreach (WaveScriptHookText::query()->where('hook_id', $hook->id)->whereIn('language', $languages)->get() as $t) {
            $byField[$t->field][$t->language] = $t->body;
        }
        $images = WaveScriptImage::query()->where('hook_id', $hook->id)->where('status', WaveScriptImage::READY)->orderBy('position')->orderBy('id')->get();

        return self::hook($hook, $byField, self::images($images, self::scriptBase($script)));
    }

    /**
     * One line. `$texts` maps language => body (the market's languages only: kept texts of a removed language are hidden).
     *
     * @param  array<string, string>  $texts
     * @param  iterable<WaveScriptBroll>  $broll  the line's rows in order
     * @param  list<array<string, mixed>>  $images
     * @return array<string, mixed>
     */
    public static function line(WaveScriptLine $line, array $texts, iterable $broll, array $images): array
    {
        $rows = [];
        foreach ($broll as $row) {
            $rows[] = [
                'id' => $row->ulid,
                'position' => $row->position,
                'code' => ScriptCodes::brollCode($line->position, $row->position),
                'shot_type' => $row->shot_type,
                'description' => $row->description,
            ];
        }

        return [
            'id' => $line->ulid,
            'position' => $line->position,
            'number' => ScriptCodes::lineNumber($line->position),
            'cta' => $line->cta,
            'texts' => $texts,
            'broll' => $rows,
            'images' => $images,
            'revision' => $line->revision,
        ];
    }

    /**
     * The ready images of a block, in order.
     *
     * @param  iterable<WaveScriptImage>  $images
     * @return list<array<string, mixed>>
     */
    public static function images(iterable $images, string $scriptBase): array
    {
        $out = [];
        foreach ($images as $image) {
            $out[] = [
                'id' => $image->ulid,
                'position' => $image->position,
                'original_filename' => $image->original_filename,
                'mime_type' => $image->mime_type,
                'size_bytes' => $image->size_bytes,
                'has_thumbnail' => $image->thumb_key !== null,
                'file_url' => $scriptBase.'/images/'.$image->ulid.'/file',
                'thumbnail_url' => $image->thumb_key !== null ? $scriptBase.'/images/'.$image->ulid.'/thumbnail' : null,
            ];
        }

        return $out;
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
     * One hook. `$texts` maps field => language => body (the market's languages only).
     *
     * @param  array<string, array<string, string>>  $texts
     * @param  list<array<string, mixed>>  $images
     * @return array<string, mixed>
     */
    public static function hook(WaveScriptHook $hook, array $texts, array $images = []): array
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
            'images' => $images,
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
        $creatorIds = $scripts->pluck('creator_id')->unique()->all();
        $inMarket = WaveCreator::query()->where('wave_id', $wave->id)->where('market_id', $market->id)->whereIn('creator_id', $creatorIds)->pluck('creator_id')->all();
        $liveByCreator = WaveScript::query()->where('wave_id', $wave->id)->whereIn('creator_id', $creatorIds)->whereNull('archived_at')
            ->selectRaw('creator_id, count(*) as n')->groupBy('creator_id')->pluck('n', 'creator_id')->all();

        return array_values($scripts->map(static fn (WaveScript $s): array => [
            'id' => $s->ulid,
            'number' => $s->number,
            'hooks' => (int) $s->getAttribute('hooks_count'),
            'creator' => self::creator($s->creator),
            'archived_at' => $s->archived_at?->toIso8601String(),
            // Archived scripts come back on their own when this creator is added to this market again.
            'creator_in_market' => in_array($s->creator_id, $inMarket, true),
            // "Restore" works when the creator is in the market and has fewer than three live scripts.
            'can_restore' => in_array($s->creator_id, $inMarket, true) && (int) ($liveByCreator[$s->creator_id] ?? 0) < StrategyLimits::SCRIPTS_PER_CREATOR,
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

    /** The API path of the script (image URLs hang on it). One query. */
    private static function scriptBase(WaveScript $script): string
    {
        $ids = DB::table('waves')->join('brands', 'brands.id', '=', 'waves.brand_id')->join('agencies', 'agencies.id', '=', 'waves.agency_id')
            ->where('waves.id', $script->wave_id)->first(['agencies.ulid as agency', 'brands.ulid as brand', 'waves.ulid as wave']);

        return '/api/v1/agencies/'.($ids->agency ?? '').'/brands/'.($ids->brand ?? '').'/waves/'.($ids->wave ?? '').'/strategy/scripts/'.$script->ulid;
    }
}
