<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Database\Factories;

use App\Modules\Strategy\Enums\PersonaPronoun;
use App\Modules\Strategy\Models\WavePersona;
use App\Modules\Waves\Models\Wave;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WavePersona>
 */
final class WavePersonaFactory extends Factory
{
    protected $model = WavePersona::class;

    /**
     * The market defaults to the wave's first market; slot and code to the first free slot of that market.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'wave_id' => fn () => Wave::factory()->create()->id,
            'agency_id' => fn (array $a) => (int) Wave::withoutGlobalScopes()->whereKey($a['wave_id'])->value('agency_id'),
            'market' => fn (array $a) => (string) (Wave::withoutGlobalScopes()->findOrFail($a['wave_id'])->markets[0] ?? 'SK'),
            'slot' => fn (array $a) => self::freeSlot((int) $a['wave_id'], (string) $a['market']),
            'code' => fn (array $a) => $a['market'].$a['slot'],
            'title' => 'The Persona '.fake()->unique()->numberBetween(1, 999999),
            'name' => 'Zuzana',
            'pronoun' => PersonaPronoun::They,
            'summary' => null,
            'core_job' => null,
            'need' => null,
            'drivers' => null,
            'goals' => null,
            'self_talk' => null,
            'barriers' => null,
        ];
    }

    private static function freeSlot(int $waveId, string $market): int
    {
        $used = WavePersona::withoutGlobalScopes()->where('wave_id', $waveId)->where('market', $market)->pluck('slot')->all();
        for ($slot = 1; $slot <= 8; $slot++) {
            if (! in_array($slot, $used, true)) {
                return $slot;
            }
        }

        return 9;
    }
}
