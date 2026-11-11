<?php

declare(strict_types=1);

namespace Tests\Fixtures\JobsBoard;

use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Enums\CampaignStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Creators\Features\CampaignBrandExclusivityEnabled;
use Laravel\Pennant\Feature;

/**
 * AH-120 — the brand-exclusivity happy path, extending the jobs-board one: the
 * rostered creator of {@see CreatorJobFixture} (the "Yahoo" job, brand
 * Northwind Coffee) and, in the SAME agency, a "Zava" campaign that blocks the
 * Yahoo brand for its selected creators.
 *
 * Nothing blocks until {@see select()} gives the creator an assignment on the
 * Zava campaign: every §5.34 negative starts from this identical base and
 * varies exactly one thing.
 */
final class ExclusivityFixture
{
    public const string ZAVA_CAMPAIGN_NAME = 'Zava spring launch';

    public function __construct(
        public readonly CreatorJobFixture $job,
        public readonly Brand $zava,
        public readonly Campaign $zavaCampaign,
    ) {}

    /**
     * @param  array<string, mixed>  $zavaCampaignOverrides
     */
    public static function make(?string $endsOn = null, bool $flag = true, array $zavaCampaignOverrides = []): self
    {
        // Explicit both ways: Pennant state outlives a call within one test.
        $flag
            ? Feature::activate(CampaignBrandExclusivityEnabled::NAME)
            : Feature::deactivate(CampaignBrandExclusivityEnabled::NAME);

        $job = CreatorJobFixture::make();
        $zava = Brand::factory()->forAgency($job->agency->id)->createOne(['name' => 'Zava']);

        $zavaCampaign = Campaign::factory()
            ->blocking([$job->brand], $endsOn)
            ->createOne(array_merge([
                'agency_id' => $job->agency->id,
                'brand_id' => $zava->id,
                'name' => self::ZAVA_CAMPAIGN_NAME,
                'status' => CampaignStatus::Active,
            ], $zavaCampaignOverrides));

        return new self($job, $zava, $zavaCampaign);
    }

    /** Give the creator an assignment on the Zava campaign in this state. */
    public function select(AssignmentStatus $status = AssignmentStatus::Accepted): CampaignAssignment
    {
        return CampaignAssignment::factory()->createOne([
            'campaign_id' => $this->zavaCampaign->id,
            'creator_id' => $this->job->creator->id,
            'status' => $status,
        ]);
    }
}
