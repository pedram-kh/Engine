<?php

declare(strict_types=1);

namespace App\Modules\Boards\Database\Factories;

use App\Modules\Boards\Models\Board;
use App\Modules\Boards\Models\BoardColumn;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BoardColumn>
 */
final class BoardColumnFactory extends Factory
{
    protected $model = BoardColumn::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'board_id' => BoardFactory::new(),
            // agency_id is denormalized from the parent board.
            'agency_id' => fn (array $attributes) => Board::withoutGlobalScopes()
                ->whereKey($attributes['board_id'])
                ->value('agency_id'),
            'name' => 'To Define',
            'position' => 1,
            'color_token' => 'status-todefine',
            'is_terminal_success' => false,
            'is_terminal_failure' => false,
        ];
    }

    public function forBoard(Board $board): static
    {
        return $this->state(fn (array $attributes): array => [
            'board_id' => $board->id,
            'agency_id' => $board->agency_id,
        ]);
    }
}
