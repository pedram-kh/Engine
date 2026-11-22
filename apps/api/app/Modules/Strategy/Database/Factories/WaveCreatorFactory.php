<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Database\Factories;

use App\Modules\Creators\Models\Creator;
use App\Modules\Strategy\Models\WaveCreator;
use App\Modules\Waves\Database\Factories\WaveFactory;
use App\Modules\Waves\Models\Wave;
use App\Modules\Waves\Models\WaveMarket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WaveCreator>
 */
final class WaveCreatorFactory extends Factory
{
    protected $model = WaveCreator::class;

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
            'market_id' => fn (array $a) => (int) (WaveMarket::withoutGlobalScopes()->where('wave_id', $a['wave_id'])->orderBy('position')->orderBy('id')->value('id')
                ?? WaveFactory::attachMarkets(Wave::withoutGlobalScopes()->whereKey($a['wave_id'])->firstOrFail(), [['name' => 'Slovakia', 'code' => 'SK', 'countries' => ['SK']]])[0]->id),
            'creator_id' => fn () => Creator::factory()->create()->id,
            'persona_id' => null,
            'slot' => fn (array $a) => self::freeSlot((int) $a['market_id']),
            'slot_code' => fn (array $a) => (string) WaveMarket::withoutGlobalScopes()->whereKey($a['market_id'])->value('code').'-C'.$a['slot'],
            'added_by_user_id' => null,
        ];
    }

    private static function freeSlot(int $marketId): int
    {
        $used = WaveCreator::withoutGlobalScopes()->where('market_id', $marketId)->pluck('slot')->all();
        for ($slot = 1; $slot <= 99; $slot++) {
            if (! in_array($slot, $used, true)) {
                return $slot;
            }
        }

        return 99;
    }
}
