<?php

declare(strict_types=1);

namespace App\Modules\Waves\Database\Factories;

use App\Modules\Waves\Enums\WaveStepSource;
use App\Modules\Waves\Enums\WaveStepStatus;
use App\Modules\Waves\Enums\WaveStream;
use App\Modules\Waves\Models\Wave;
use App\Modules\Waves\Models\WaveStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WaveStep>
 */
final class WaveStepFactory extends Factory
{
    protected $model = WaveStep::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'wave_id' => fn () => Wave::factory()->create()->id,
            'agency_id' => fn (array $a) => (int) Wave::withoutGlobalScopes()->whereKey($a['wave_id'])->value('agency_id'),
            'stream' => WaveStream::Statics,
            'step_key' => null,
            'name' => 'Step '.fake()->unique()->numberBetween(1, 999999),
            'position' => fake()->unique()->numberBetween(1000, 999999),
            'status' => WaveStepStatus::NotStarted,
            'owner_user_id' => null,
            'owner_client' => false,
            'status_source' => WaveStepSource::System,
        ];
    }
}
