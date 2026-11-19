<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Database\Factories;

use App\Modules\Strategy\Enums\UspColor;
use App\Modules\Strategy\Models\WaveUsp;
use App\Modules\Waves\Models\Wave;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WaveUsp>
 */
final class WaveUspFactory extends Factory
{
    protected $model = WaveUsp::class;

    /**
     * The colour and position default to the FIRST FREE ones of the wave, so a factory loop never clashes with
     * `unique (wave_id, color_key)`.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'wave_id' => fn () => Wave::factory()->create()->id,
            'agency_id' => fn (array $a) => (int) Wave::withoutGlobalScopes()->whereKey($a['wave_id'])->value('agency_id'),
            'color_key' => fn (array $a) => self::freeColor((int) $a['wave_id']),
            'position' => fn (array $a) => (int) WaveUsp::withoutGlobalScopes()->where('wave_id', $a['wave_id'])->max('position') + 1,
            'title' => 'USP '.fake()->unique()->numberBetween(1, 999999),
            'explainer' => null,
            'creative_angle' => null,
            'copy_seed' => null,
        ];
    }

    private static function freeColor(int $waveId): UspColor
    {
        $used = WaveUsp::withoutGlobalScopes()->where('wave_id', $waveId)->pluck('color_key')
            ->map(static fn (mixed $c): string => $c instanceof UspColor ? $c->value : (string) $c)->all();

        foreach (UspColor::cases() as $color) {
            if (! in_array($color->value, $used, true)) {
                return $color;
            }
        }

        return UspColor::Violet;
    }
}
