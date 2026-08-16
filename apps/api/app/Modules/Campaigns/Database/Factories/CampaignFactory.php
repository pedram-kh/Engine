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
            // AH-069 D1 — the safety floor, mirrored into the factory so every
            // pre-existing test keeps the lifecycle it was written against.
            'creator_posts_content' => true,
            'listed_on_jobs_board' => false,
            'listing_duration' => null,
            'listing_fee' => null,
            'listing_languages' => null,
            'listing_regions' => null,
            'listing_examples_url' => null,
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
     * Every D3 listing-floor field populated, but still UNLISTED — the state a
     * campaign must reach before the jobs-board toggle will accept `true`
     * (AH-054). Deterministic on purpose: a floor fixture built from
     * `fake()->optional()` produces a randomly-red suite, which is how a gate
     * ends up "fixed" by being weakened.
     */
    public function jobReady(): static
    {
        return $this->state(fn (array $attributes): array => [
            'description' => 'Two Reels and three Stories per month, organic usage for 6 months.',
            'listing_duration' => '4 weeks',
            'listing_fee' => '€300 per video',
            'listing_languages' => ['en', 'fr'],
            'listing_regions' => ['IE', 'FR'],
            'listing_examples_url' => 'https://example.com/reference-work',
        ]);
    }

    /**
     * Job-ready AND live on the board — the post-toggle state.
     *
     * `listed_at` is stamped alongside the flag (AH-056 D4) so a fixture
     * matches what the production flip actually writes. A test that needs the
     * null-`listed_at` degradation (a campaign listed before the column
     * existed) overrides it explicitly.
     */
    public function listed(): static
    {
        return $this->jobReady()->state(fn (array $attributes): array => [
            'listed_on_jobs_board' => true,
            'listed_at' => now(),
        ]);
    }

    /**
     * The AH-069 toggle-OFF campaign: the creator does NOT post the deliverable,
     * so approving a draft completes the assignment
     * (`approved → completed_on_approval`, D3).
     */
    public function handsOffAtApproval(): static
    {
        return $this->state(fn (array $attributes): array => [
            'creator_posts_content' => false,
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
