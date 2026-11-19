<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Http\Resources;

use App\Modules\Strategy\Models\WavePersona;
use App\Modules\Strategy\Models\WavePersonaAngle;
use App\Modules\Strategy\Models\WaveUsp;
use App\Modules\Strategy\StrategyLimits;
use App\Modules\Waves\Models\Wave;

/**
 * The strategy response shapes (JSON:API-like `id` / `type` / `attributes`). The `id` is the ulid; the numeric id,
 * `agency_id` and `wave_id` never leave the API.
 */
final class StrategyPresenter
{
    /**
     * @param  array<string, int>  $personaCounts  market => personas
     * @return array<string, mixed>
     */
    public static function summary(Wave $wave, int $uspCount, array $personaCounts): array
    {
        $markets = [];
        foreach ($wave->markets as $code) {
            $markets[] = ['market' => $code, 'persona_count' => $personaCounts[$code] ?? 0];
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
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function usp(WaveUsp $usp, int $angleCount): array
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
                'market' => $persona->market,
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
                    ? $persona->angles->map(static fn (WavePersonaAngle $a): array => self::angle($a))->values()->all()
                    : [],
                'created_at' => $persona->created_at->toIso8601String(),
                'updated_at' => $persona->updated_at->toIso8601String(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function angle(WavePersonaAngle $angle): array
    {
        return [
            'id' => $angle->ulid,
            'type' => 'wave_persona_angle',
            'attributes' => [
                'usp' => $angle->usp->ulid,
                'usp_title' => $angle->usp->title,
                'color_key' => $angle->usp->color_key->value,
                'explanation' => $angle->explanation,
                'origin' => $angle->origin->value,
            ],
        ];
    }
}
