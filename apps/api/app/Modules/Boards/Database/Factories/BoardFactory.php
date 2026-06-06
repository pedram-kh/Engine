<?php

declare(strict_types=1);

namespace App\Modules\Boards\Database\Factories;

use App\Modules\Boards\Models\Board;
use App\Modules\Campaigns\Database\Factories\CampaignFactory;
use App\Modules\Campaigns\Models\Campaign;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Board>
 */
final class BoardFactory extends Factory
{
    protected $model = Board::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'campaign_id' => CampaignFactory::new(),
            // agency_id is denormalized from the parent campaign.
            'agency_id' => fn (array $attributes) => Campaign::withoutGlobalScopes()
                ->whereKey($attributes['campaign_id'])
                ->value('agency_id'),
        ];
    }

    public function forCampaign(Campaign $campaign): static
    {
        return $this->state(fn (array $attributes): array => [
            'campaign_id' => $campaign->id,
            'agency_id' => $campaign->agency_id,
        ]);
    }
}
