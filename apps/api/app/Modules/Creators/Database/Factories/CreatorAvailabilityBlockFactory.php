<?php

declare(strict_types=1);

namespace App\Modules\Creators\Database\Factories;

use App\Modules\Creators\Enums\BlockType;
use App\Modules\Creators\Enums\Kind;
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
            'kind' => fake()->randomElement([Kind::Vacation, Kind::Personal, Kind::Other]),
            'block_type' => BlockType::Hard,
            'reason' => fake()->optional()->sentence(),
            'assignment_id' => null,
            'is_recurring' => false,
        ];
    }

    public function hard(): self
    {
        return $this->state(['block_type' => BlockType::Hard]);
    }

    public function soft(): self
    {
        return $this->state(['block_type' => BlockType::Soft]);
    }

    /**
     * A weekly-recurring block. The window-expansion engine reads the rule
     * from `recurrence_rule`; `starts_at`/`ends_at` define the first
     * occurrence's clock-time + duration anchor.
     */
    public function weeklyRecurring(string $rule = 'FREQ=WEEKLY;BYDAY=MO'): self
    {
        return $this->state([
            'is_recurring' => true,
            'recurrence_rule' => $rule,
        ]);
    }
}
