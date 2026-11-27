<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Services;

use App\Modules\Strategy\Enums\MatrixLevel;
use App\Modules\Strategy\Models\WaveMatrixCell;
use App\Modules\Strategy\Models\WavePersona;
use App\Modules\Strategy\Models\WavePersonaAngle;
use App\Modules\Strategy\Models\WaveScript;

/**
 * The brief of a script (PL-3.2a, plan 1.4): what is STORED on the script (the angle once edited, the duration, the overall setting) and what is READ
 * LIVE from the strategy (the creator's matched persona, the persona's Priority and Support USPs, the angle line while the angle
 * is not edited). Nothing live is copied, so nothing goes stale: a persona change or a matrix change shows at the next load.
 *
 * No persona (the slot has none, or it was deleted: a persona in use by creators cannot be deleted, so the first case): every
 * live part is empty and the angle is the script's own text or nothing. Three reads regardless of the script: the persona,
 * the cells with their USPs, the angle line.
 */
final class ScriptBriefReader
{
    /**
     * @return array{persona: array{id: string, code: string, title: string, slot: int, color_key: string}|null, usps: list<array{id: string, title: string, color_key: string, level: string}>, angle: array{value: string|null, source: string, custom: bool}, duration_seconds: int, setting: string|null, revision: int}
     */
    public static function read(WaveScript $script, ?int $personaId): array
    {
        $persona = $personaId === null ? null : WavePersona::query()->where('wave_id', $script->wave_id)->whereKey($personaId)->first();

        $usps = [];
        $liveAngle = null;
        $liveSource = 'none';
        if ($persona !== null) {
            $cells = WaveMatrixCell::query()
                ->where('wave_matrix_cells.persona_id', $persona->id)
                ->whereIn('wave_matrix_cells.level', [MatrixLevel::Priority->value, MatrixLevel::Support->value])
                ->join('wave_usps', 'wave_usps.id', '=', 'wave_matrix_cells.usp_id')
                ->orderByRaw("case when wave_matrix_cells.level = 'priority' then 0 else 1 end")
                ->orderBy('wave_usps.position')->orderBy('wave_usps.id')
                ->get(['wave_usps.id as usp_id', 'wave_usps.ulid as usp_ulid', 'wave_usps.title', 'wave_usps.color_key', 'wave_matrix_cells.level', 'wave_matrix_cells.rationale']);

            $firstPriority = null;
            foreach ($cells as $cell) {
                $level = (string) $cell->getRawOriginal('level');   // raw: the model casts `level` to MatrixLevel
                $usps[] = ['id' => (string) $cell->getAttribute('usp_ulid'), 'title' => (string) $cell->getAttribute('title'), 'color_key' => (string) $cell->getAttribute('color_key'), 'level' => $level];
                if ($firstPriority === null && $level === MatrixLevel::Priority->value) {
                    $firstPriority = $cell;
                }
            }
            if ($firstPriority !== null) {
                $angle = WavePersonaAngle::query()->where('persona_id', $persona->id)->where('usp_id', (int) $firstPriority->getAttribute('usp_id'))->orderBy('id')->value('explanation');
                if (is_string($angle) && trim($angle) !== '') {
                    $liveAngle = $angle;
                    $liveSource = 'persona';
                } elseif (is_string($firstPriority->getAttribute('rationale')) && trim((string) $firstPriority->getAttribute('rationale')) !== '') {
                    $liveAngle = (string) $firstPriority->getAttribute('rationale');
                    $liveSource = 'matrix';
                }
            }
        }

        $custom = $script->angle !== null;

        return [
            'persona' => $persona === null ? null : ['id' => $persona->ulid, 'code' => $persona->code, 'title' => $persona->title, 'slot' => $persona->slot, 'color_key' => $persona->colorKey()->value],
            'usps' => $usps,
            'angle' => ['value' => $custom ? $script->angle : $liveAngle, 'source' => $custom ? 'script' : $liveSource, 'custom' => $custom],
            'duration_seconds' => $script->duration_seconds,
            'setting' => $script->setting,
            'revision' => $script->brief_revision,
        ];
    }
}
