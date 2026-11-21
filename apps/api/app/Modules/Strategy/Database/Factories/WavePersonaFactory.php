<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Database\Factories;

use App\Modules\Strategy\Enums\PersonaPronoun;
use App\Modules\Strategy\Models\WavePersona;
use App\Modules\Waves\Database\Factories\WaveFactory;
use App\Modules\Waves\Models\Wave;
use App\Modules\Waves\Models\WaveMarket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WavePersona>
 */
final class WavePersonaFactory extends Factory
{
    protected $model = WavePersona::class;

    /**
     * The market defaults to the wave's first market (one is made when the wave has none); slot and code to the first free slot of that market.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'wave_id' => fn () => Wave::factory()->create()->id,
            'agency_id' => fn (array $a) => (int) Wave::withoutGlobalScopes()->whereKey($a['wave_id'])->value('agency_id'),
            'market_id' => fn (array $a) => (int) (WaveMarket::withoutGlobalScopes()->where('wave_id', $a['wave_id'])->orderBy('position')->orderBy('id')->value('id')
                ?? WaveFactory::attachMarkets(Wave::withoutGlobalScopes()->findOrFail($a['wave_id']), [['name' => 'Slovakia', 'code' => 'SK', 'countries' => ['SK']]])[0]->id),
            'slot' => fn (array $a) => self::freeSlot((int) $a['wave_id'], (int) $a['market_id']),
            'code' => fn (array $a) => (string) WaveMarket::withoutGlobalScopes()->whereKey($a['market_id'])->value('code').$a['slot'],
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

    private static function freeSlot(int $waveId, int $marketId): int
    {
        $used = WavePersona::withoutGlobalScopes()->where('wave_id', $waveId)->where('market_id', $marketId)->pluck('slot')->all();
        for ($slot = 1; $slot <= 8; $slot++) {
            if (! in_array($slot, $used, true)) {
                return $slot;
            }
        }

        return 9;
    }
}
