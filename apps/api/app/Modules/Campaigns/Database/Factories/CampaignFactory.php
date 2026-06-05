<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Database\Factories;

use App\Modules\Agencies\Database\Factories\AgencyFactory;
use App\Modules\Brands\Database\Factories\BrandFactory;
use App\Modules\Campaigns\Enums\CampaignObjective;
use App\Modules\Campaigns\Enums\CampaignStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Identity\Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Campaign>
 */
final class CampaignFactory extends Factory
{
    protected $model = Campaign::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agency_id' => AgencyFactory::new(),
            // The brand MUST belong to the same agency as the campaign.
            'brand_id' => fn (array $attributes) => BrandFactory::new()->forAgency((int) $attributes['agency_id']),
            'name' => fake()->unique()->company().' campaign',
            'description' => fake()->optional()->paragraph(),
            'objective' => CampaignObjective::Awareness,
            'status' => CampaignStatus::Draft,
            'budget_minor_units' => 1_000_000,
            'budget_currency' => 'EUR',
            'starts_at' => null,
            'ends_at' => null,
            'posting_window_starts_at' => null,
            'posting_window_ends_at' => null,
            'brief' => null,
            'target_creator_count' => null,
            'created_by_user_id' => UserFactory::new(),
            'published_at' => null,
            'completed_at' => null,
            'is_marketplace_visible' => false,
            'marketplace_open_at' => null,
            'marketplace_close_at' => null,
            'requires_per_campaign_contract' => false,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => CampaignStatus::Active,
            'published_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $brief
     */
    public function withBrief(array $brief): static
    {
        return $this->state(fn (array $attributes): array => [
            'brief' => $brief,
        ]);
    }

    public function forAgency(int $agencyId): static
    {
        return $this->state(fn (array $attributes): array => [
            'agency_id' => $agencyId,
            'brand_id' => BrandFactory::new()->forAgency($agencyId),
        ]);
    }
}
