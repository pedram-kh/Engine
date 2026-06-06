<?php

declare(strict_types=1);

namespace App\Modules\Boards\Database\Factories;

use App\Modules\Boards\Models\Board;
use App\Modules\Boards\Models\BoardCard;
use App\Modules\Boards\Models\BoardColumn;
use App\Modules\Campaigns\Database\Factories\CampaignAssignmentFactory;
use App\Modules\Campaigns\Models\CampaignAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BoardCard>
 */
final class BoardCardFactory extends Factory
{
    protected $model = BoardCard::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'board_id' => BoardFactory::new(),
            'column_id' => BoardColumnFactory::new(),
            // agency_id is denormalized from the parent board.
            'agency_id' => fn (array $attributes) => Board::withoutGlobalScopes()
                ->whereKey($attributes['board_id'])
                ->value('agency_id'),
            'assignment_id' => CampaignAssignmentFactory::new(),
            'position' => 0,
        ];
    }

    public function forBoard(Board $board): static
    {
        return $this->state(fn (array $attributes): array => [
            'board_id' => $board->id,
            'agency_id' => $board->agency_id,
        ]);
    }

    public function inColumn(BoardColumn $column): static
    {
        return $this->state(fn (array $attributes): array => [
            'column_id' => $column->id,
        ]);
    }

    public function forAssignment(CampaignAssignment $assignment): static
    {
        return $this->state(fn (array $attributes): array => [
            'assignment_id' => $assignment->id,
            'agency_id' => $assignment->agency_id,
        ]);
    }
}
