<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Database\Factories;

use App\Modules\Campaigns\Models\CampaignJobNotification;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CampaignJobNotification>
 */
final class CampaignJobNotificationFactory extends Factory
{
    protected $model = CampaignJobNotification::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'campaign_id' => CampaignFactory::new(),
            'creator_id' => CreatorFactory::new(),
            'notified_at' => now(),
        ];
    }
}
