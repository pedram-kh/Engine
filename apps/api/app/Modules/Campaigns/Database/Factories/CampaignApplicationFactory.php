<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Database\Factories;

use App\Modules\Campaigns\Enums\CampaignApplicationStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignApplication;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CampaignApplication>
 */
final class CampaignApplicationFactory extends Factory
{
    protected $model = CampaignApplication::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'campaign_id' => CampaignFactory::new(),
            // Denormalized from the parent campaign — the same resolution the
            // assignment factory uses, and the same value the production
            // insert site sets.
            'agency_id' => fn (array $attributes) => Campaign::withoutGlobalScopes()->whereKey($attributes['campaign_id'])->value('agency_id'),
            'creator_id' => CreatorFactory::new(),
            'status' => CampaignApplicationStatus::Pending,
            'note' => null,
            'responded_at' => null,
        ];
    }

    public function status(CampaignApplicationStatus $status): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => $status,
            'responded_at' => $status->isTerminal() ? now() : null,
        ]);
    }

    public function withNote(string $note): static
    {
        return $this->state(fn (array $attributes): array => [
            'note' => $note,
        ]);
    }
}
