<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Database\Factories;

use App\Modules\Campaigns\Enums\DraftReviewStatus;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Campaigns\Models\CampaignDraft;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CampaignDraft>
 */
final class CampaignDraftFactory extends Factory
{
    protected $model = CampaignDraft::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'assignment_id' => CampaignAssignmentFactory::new(),
            'submitted_by_creator_id' => fn (array $attributes) => CampaignAssignment::withoutGlobalScopes()
                ->whereKey($attributes['assignment_id'])
                ->value('creator_id'),
            'version' => 1,
            'submitted_at' => now(),
            'caption' => $this->faker->sentence(),
            'hashtags' => ['#ad', '#sponsored'],
            'mentions' => ['@brand'],
            'media_attachments' => [[
                's3_path' => 'creators/draft/'.$this->faker->uuid().'.mp4',
                'mime_type' => 'video/mp4',
                'kind' => 'video',
                'thumbnail_path' => null,
                'duration_seconds' => 30,
            ]],
            'review_status' => DraftReviewStatus::Pending,
        ];
    }

    public function version(int $version): static
    {
        return $this->state(fn (array $attributes): array => ['version' => $version]);
    }

    public function reviewStatus(DraftReviewStatus $status): static
    {
        return $this->state(fn (array $attributes): array => ['review_status' => $status]);
    }
}
