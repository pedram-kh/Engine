<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Database\Factories;

use App\Modules\Agencies\Database\Factories\AgencyFileFactory;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CampaignAttachment>
 */
final class CampaignAttachmentFactory extends Factory
{
    protected $model = CampaignAttachment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'campaign_id' => CampaignFactory::new(),
            // Same-agency by default (the CampaignApplicationFactory
            // resolution shape) — a cross-agency pair is only ever built
            // explicitly, in the one test that needs it.
            'agency_file_id' => fn (array $attributes) => AgencyFileFactory::new()
                ->forAgency((int) Campaign::withoutGlobalScopes()->whereKey($attributes['campaign_id'])->value('agency_id'))
                ->createOne()
                ->id,
            'visible_to_creators' => false,
            'attached_by_user_id' => null,
        ];
    }

    public function visible(): static
    {
        return $this->state(fn (array $attributes): array => [
            'visible_to_creators' => true,
        ]);
    }

    public function attachedBy(int $userId): static
    {
        return $this->state(fn (array $attributes): array => [
            'attached_by_user_id' => $userId,
        ]);
    }
}
