<?php

declare(strict_types=1);

namespace App\Modules\Creators\Database\Factories;

use App\Modules\Creators\Enums\KycVerificationStatus;
use App\Modules\Creators\Models\CreatorKycVerification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreatorKycVerification>
 */
final class CreatorKycVerificationFactory extends Factory
{
    protected $model = CreatorKycVerification::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'creator_id' => CreatorFactory::new(),
            'provider' => 'mock',
            'provider_session_id' => fake()->uuid(),
            'provider_decision_id' => null,
            'status' => KycVerificationStatus::Started,
            'decision_data' => null,
            'failure_reason' => null,
            'started_at' => now(),
            'completed_at' => null,
            'expires_at' => now()->addDays(30),
        ];
    }

    public function passed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => KycVerificationStatus::Passed,
            'provider_decision_id' => fake()->uuid(),
            'decision_data' => ['outcome' => 'approved', 'confidence' => 0.97],
            'completed_at' => now(),
        ]);
    }
}
