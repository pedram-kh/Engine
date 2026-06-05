<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Database\Factories;

use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CampaignAssignment>
 */
final class CampaignAssignmentFactory extends Factory
{
    protected $model = CampaignAssignment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'campaign_id' => CampaignFactory::new(),
            // agency_id / brand_id are denormalized from the parent campaign.
            'agency_id' => fn (array $attributes) => Campaign::withoutGlobalScopes()->whereKey($attributes['campaign_id'])->value('agency_id'),
            'brand_id' => fn (array $attributes) => Campaign::withoutGlobalScopes()->whereKey($attributes['campaign_id'])->value('brand_id'),
            'creator_id' => CreatorFactory::new(),
            'status' => AssignmentStatus::Invited,
            'invited_at' => now(),
            'agreed_fee_minor_units' => 500_000,
            'agreed_fee_currency' => 'EUR',
            'deliverables' => null,
        ];
    }

    public function status(AssignmentStatus $status): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => $status,
        ]);
    }
}
