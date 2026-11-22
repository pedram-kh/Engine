<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Database\Factories;

use App\Modules\Strategy\Enums\CreatorDecisionStatus;
use App\Modules\Strategy\Models\WaveCreator;
use App\Modules\Strategy\Models\WaveCreatorDecision;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WaveCreatorDecision>
 */
final class WaveCreatorDecisionFactory extends Factory
{
    protected $model = WaveCreatorDecision::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'wave_creator_id' => fn () => WaveCreator::factory()->create()->id,
            'wave_id' => fn (array $a) => (int) WaveCreator::withoutGlobalScopes()->whereKey($a['wave_creator_id'])->value('wave_id'),
            'agency_id' => fn (array $a) => (int) WaveCreator::withoutGlobalScopes()->whereKey($a['wave_creator_id'])->value('agency_id'),
            'status' => CreatorDecisionStatus::Approved,
            'client_name' => 'Anna K.',
            'note' => null,
            'comment' => null,
            'recorded_by_user_id' => null,
            'recorded_at' => now(),
        ];
    }
}
