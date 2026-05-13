<?php

declare(strict_types=1);

namespace App\Modules\Creators\Database\Factories;

use App\Modules\Creators\Models\CreatorAvailabilityBlock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreatorAvailabilityBlock>
 */
final class CreatorAvailabilityBlockFactory extends Factory
{
    protected $model = CreatorAvailabilityBlock::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = fake()->dateTimeBetween('+1 day', '+30 days');
        $end = (clone $start)->modify('+'.fake()->numberBetween(1, 7).' days');

        return [
            'creator_id' => CreatorFactory::new(),
            'starts_at' => $start,
            'ends_at' => $end,
            'is_all_day' => true,
            'kind' => fake()->randomElement(['vacation', 'personal', 'other']),
            'block_type' => 'hard',
            'reason' => fake()->optional()->sentence(),
            'assignment_id' => null,
            'is_recurring' => false,
        ];
    }
}
