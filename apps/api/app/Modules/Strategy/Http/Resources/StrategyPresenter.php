<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Http\Resources;

use App\Modules\Strategy\Enums\AngleOrigin;
use App\Modules\Strategy\Models\WaveMatrixCell;
use App\Modules\Strategy\Models\WavePersona;
use App\Modules\Strategy\Models\WavePersonaAngle;
use App\Modules\Strategy\Models\WaveUsp;
use App\Modules\Strategy\Services\ConceptService;
use App\Modules\Strategy\StrategyLimits;
use App\Modules\Waves\Models\Wave;

/**
 * The strategy response shapes (JSON:API-like `id` / `type` / `attributes`). The `id` is the ulid; the numeric id,
 * `agency_id` and `wave_id` never leave the API.
 */
final class StrategyPresenter
{
    /**
     * @param  array<int, int>  $personaCounts  market id => personas
     * @param  array{cells_set: int, cells_total: int, by_market: list<array{market: string, set: int, total: int}>}|null  $matrix
     * @param  array<string, mixed>|null  $concepts  {@see ConceptService::summary}
     * @return array<string, mixed>
     */
    public static function summary(Wave $wave, int $uspCount, array $personaCounts, ?array $matrix = null, ?array $concepts = null): array
    {
        $wave->loadMissing('markets.countries');
        $markets = [];
        foreach ($wave->markets as $market) {
            // PL-2.4: `market` is the ulid; the name, short code and countries are what the tabs show.
            $markets[] = [
                'market' => $market->ulid,
                'name' => $market->name,
                'code' => $market->code,
                'countries' => $market->countryCodes(),
                'persona_count' => $personaCounts[$market->id] ?? 0,
            ];
        }

        return [
            'id' => $wave->ulid,
            'type' => 'wave_strategy',
            'attributes' => [
                'usp_count' => $uspCount,
                'audience_title' => $wave->usp_audience_title,
                'persona_count' => array_sum(array_column($markets, 'persona_count')),
                'markets' => $markets,
                'limits' => StrategyLimits::all(),
                'matrix' => $matrix ?? ['cells_set' => 0, 'cells_total' => 0, 'by_market' => []],
                'concepts' => $concepts ?? ['total' => 0, 'approved' => 0, 'awaiting' => 0, 'changes_requested' => 0, 'markets_complete' => 0, 'by_market' => []],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function usp(WaveUsp $usp, int $angleCount, int $cellCount = 0): array
    {
        return [
            'id' => $usp->ulid,
            'type' => 'wave_usp',
            'attributes' => [
                'color_key' => $usp->color_key->value,
                'position' => $usp->position,
                'title' => $usp->title,
                'explainer' => $usp->explainer,
                'creative_angle' => $usp->creative_angle,
                'copy_seed' => $usp->copy_seed,
                'angle_count' => $angleCount,
                'cell_count' => $cellCount,
                'created_at' => $usp->created_at->toIso8601String(),
                'updated_at' => $usp->updated_at->toIso8601String(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function persona(WavePersona $persona): array
    {
        return [
            'id' => $persona->ulid,
            'type' => 'wave_persona',
            'attributes' => [
                'market' => $persona->market->ulid,
                'slot' => $persona->slot,
                'color_key' => $persona->colorKey()->value,
                'code' => $persona->code,
                'title' => $persona->title,
                'name' => $persona->name,
                'pronoun' => $persona->pronoun->value,
                'summary' => $persona->summary,
                'core_job' => $persona->core_job,
                'need' => $persona->need,
                'drivers' => $persona->drivers ?? [],
                'goals' => $persona->goals ?? [],
                'self_talk' => $persona->self_talk ?? [],
                'barriers' => $persona->barriers ?? [],
                'angles' => $persona->relationLoaded('angles')
                    ? $persona->angles->map(static fn (WavePersonaAngle $a): array => self::angle($a, $persona->market->ulid))->values()->all()
                    : [],
                'matrix_cells' => $persona->relationLoaded('cells')
                    ? $persona->cells->map(static fn (WaveMatrixCell $c): array => ['usp' => $c->usp->ulid, 'level' => $c->level->value, 'message_line' => $c->message_line])->values()->all()
                    : [],
                'created_at' => $persona->created_at->toIso8601String(),
                'updated_at' => $persona->updated_at->toIso8601String(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function angle(WavePersonaAngle $angle, ?string $market = null): array
    {
        $market ??= $angle->relationLoaded('persona') ? $angle->persona->market->ulid : null;

        return [
            'id' => $angle->ulid,
            'type' => 'wave_persona_angle',
            'attributes' => [
                'usp' => $angle->usp->ulid,
                'usp_title' => $angle->usp->title,
                'color_key' => $angle->usp->color_key->value,
                'explanation' => $angle->explanation,
                'origin' => $angle->origin->value,
                'read_only' => $angle->origin === AngleOrigin::Matrix,
                'market' => $market,
            ],
        ];
    }

    /**
     * One market's matrix: the USPs in order, the personas in slot order, the set cells, and which pairs already have a
     * hand-written angle. `cells` holds one entry per SET cell; "Not set" is absence.
     *
     * @param  iterable<int, WaveUsp>  $usps
     * @param  iterable<int, WavePersona>  $personas
     * @param  iterable<int, WaveMatrixCell>  $cells
     * @param  array<string, array{ulid: string, text: string}>  $manual  "personaId:uspId" => the hand-written angle on that pair
     * @param  array{cells_set: int, cells_total: int, by_market: list<array{market: string, set: int, total: int}>}  $progress
     * @return array<string, mixed>
     */
    public static function matrix(Wave $wave, string $market, iterable $usps, iterable $personas, iterable $cells, array $manual, array $progress): array
    {
        $uspRows = [];
        $uspUlid = [];
        foreach ($usps as $u) {
            $uspUlid[$u->id] = $u->ulid;
            $uspRows[] = ['id' => $u->ulid, 'title' => $u->title, 'color_key' => $u->color_key->value, 'position' => $u->position];
        }
        $personaRows = [];
        $personaUlid = [];
        foreach ($personas as $p) {
            $personaUlid[$p->id] = $p->ulid;
            $personaRows[] = [
                'id' => $p->ulid, 'slot' => $p->slot, 'color_key' => $p->colorKey()->value, 'code' => $p->code,
                'title' => $p->title, 'name' => $p->name, 'summary' => $p->summary,
            ];
        }
        $cellRows = [];
        foreach ($cells as $c) {
            $cellRows[] = self::cell($c, $personaUlid[$c->persona_id], $uspUlid[$c->usp_id], $manual[$c->persona_id.':'.$c->usp_id] ?? null);
        }

        // Every hand-written angle of the market's personas, set cell or not (R1: a cell that is not set yet can still
        // be prefilled from it).
        $manualRows = [];
        foreach ($manual as $key => $angle) {
            [$pid, $uid] = array_map('intval', explode(':', $key));
            if (isset($personaUlid[$pid], $uspUlid[$uid])) {
                $manualRows[] = ['persona' => $personaUlid[$pid], 'usp' => $uspUlid[$uid], 'angle' => $angle['ulid'], 'text' => $angle['text']];
            }
        }

        return [
            'id' => $wave->ulid.':'.$market,
            'type' => 'wave_matrix',
            'attributes' => [
                'market' => $market,
                'usps' => $uspRows,
                'personas' => $personaRows,
                'cells' => $cellRows,
                'manual_angles' => $manualRows,
                'progress' => $progress,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function cell(?WaveMatrixCell $cell, string $persona, string $usp, ?array $manualAngle): array
    {
        return [
            'persona' => $persona,
            'usp' => $usp,
            'level' => $cell?->level->value,
            'rationale' => $cell?->rationale,
            'message_line' => $cell?->message_line,
            'source' => $cell?->source,
            'has_manual_angle' => $manualAngle !== null,
            // PL-2.2 ruling change R1/R2: the id and the text of the hand-written angle on the pair, so the editor can
            // prefill an empty message line and the note can show both texts and act on the angle. Null when there is none.
            'manual_angle' => $manualAngle === null ? null : $manualAngle['ulid'],
            'manual_text' => $manualAngle === null ? null : $manualAngle['text'],
        ];
    }
}
