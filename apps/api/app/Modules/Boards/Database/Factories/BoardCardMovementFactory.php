<?php

declare(strict_types=1);

namespace App\Modules\Boards\Database\Factories;

use App\Modules\Boards\Enums\MovementTrigger;
use App\Modules\Boards\Models\BoardCard;
use App\Modules\Boards\Models\BoardCardMovement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BoardCardMovement>
 */
final class BoardCardMovementFactory extends Factory
{
    protected $model = BoardCardMovement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'card_id' => BoardCardFactory::new(),
            'from_column_id' => null,
            'to_column_id' => null,
            'triggered_by' => MovementTrigger::User,
            'triggered_event_key' => null,
            'triggered_by_user_id' => null,
            'reason' => null,
        ];
    }

    public function forCard(BoardCard $card): static
    {
        return $this->state(fn (array $attributes): array => [
            'card_id' => $card->id,
        ]);
    }
}
