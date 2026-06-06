<?php

declare(strict_types=1);

namespace App\Modules\Boards\Database\Factories;

use App\Modules\Boards\Enums\BoardAutomationActionType;
use App\Modules\Boards\Models\Board;
use App\Modules\Boards\Models\BoardAutomation;
use App\Modules\Boards\Models\BoardColumn;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BoardAutomation>
 */
final class BoardAutomationFactory extends Factory
{
    protected $model = BoardAutomation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'board_id' => BoardFactory::new(),
            'event_key' => 'assignment.invited',
            'action_type' => BoardAutomationActionType::MoveToColumn,
            'target_column_id' => null,
            'condition' => null,
            'is_enabled' => true,
        ];
    }

    public function forBoard(Board $board): static
    {
        return $this->state(fn (array $attributes): array => [
            'board_id' => $board->id,
        ]);
    }

    public function event(string $eventKey): static
    {
        return $this->state(fn (array $attributes): array => [
            'event_key' => $eventKey,
        ]);
    }

    public function target(BoardColumn $column): static
    {
        return $this->state(fn (array $attributes): array => [
            'target_column_id' => $column->id,
        ]);
    }
}
