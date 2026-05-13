<?php

declare(strict_types=1);

namespace App\Modules\Creators\Database\Factories;

use App\Modules\Creators\Enums\PayoutStatus;
use App\Modules\Creators\Models\CreatorPayoutMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreatorPayoutMethod>
 */
final class CreatorPayoutMethodFactory extends Factory
{
    protected $model = CreatorPayoutMethod::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'creator_id' => CreatorFactory::new(),
            'provider' => 'stripe_connect',
            'provider_account_id' => 'acct_'.fake()->bothify('????????????????'),
            'currency' => 'EUR',
            'is_default' => true,
            'status' => PayoutStatus::Pending,
            'verified_at' => null,
        ];
    }

    public function verified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PayoutStatus::Verified,
            'verified_at' => now(),
        ]);
    }
}
