<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Database\Factories;

use App\Modules\Strategy\Models\WaveStrategyNote;
use App\Modules\Waves\Models\Wave;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WaveStrategyNote>
 */
final class WaveStrategyNoteFactory extends Factory
{
    protected $model = WaveStrategyNote::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'wave_id' => fn () => Wave::factory()->create()->id,
            'agency_id' => fn (array $a) => (int) Wave::withoutGlobalScopes()->whereKey($a['wave_id'])->value('agency_id'),
            'step' => 'usps',
            'market_id' => null,
            'author_user_id' => null,
            'body' => 'A note '.fake()->unique()->numberBetween(1, 999999),
            'edited_at' => null,
        ];
    }
}
