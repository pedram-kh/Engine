<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Database\Factories;

use App\Modules\Campaigns\Enums\PostedContentVerificationStatus;
use App\Modules\Campaigns\Models\CampaignPostedContent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CampaignPostedContent>
 */
final class CampaignPostedContentFactory extends Factory
{
    protected $model = CampaignPostedContent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'assignment_id' => CampaignAssignmentFactory::new(),
            'platform' => 'instagram',
            'post_url' => 'https://instagram.com/p/'.$this->faker->lexify('??????????'),
            'platform_post_id' => null,
            'posted_at' => now(),
            'verification_status' => PostedContentVerificationStatus::Pending,
        ];
    }

    public function verificationStatus(PostedContentVerificationStatus $status): static
    {
        return $this->state(fn (array $attributes): array => ['verification_status' => $status]);
    }
}
