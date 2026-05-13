<?php

declare(strict_types=1);

namespace App\Modules\Creators\Database\Factories;

use App\Modules\Creators\Enums\ApplicationStatus;
use App\Modules\Creators\Enums\KycStatus;
use App\Modules\Creators\Enums\VerificationLevel;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Database\Factories\UserFactory;
use App\Modules\Identity\Enums\UserType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Creator>
 */
final class CreatorFactory extends Factory
{
    protected $model = Creator::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => UserFactory::new()->state(['type' => UserType::Creator]),
            'display_name' => fake()->name(),
            'bio' => fake()->optional()->paragraph(),
            'country_code' => fake()->randomElement(['US', 'GB', 'PT', 'IT', 'DE', 'FR']),
            'region' => fake()->optional()->city(),
            'primary_language' => fake()->randomElement(['en', 'pt', 'it']),
            'secondary_languages' => null,
            'avatar_path' => null,
            'cover_path' => null,
            'categories' => fake()->randomElements(['lifestyle', 'fashion', 'fitness', 'travel', 'food', 'tech'], 2),
            'verification_level' => VerificationLevel::Unverified,
            'tier' => null,
            'application_status' => ApplicationStatus::Incomplete,
            'profile_completeness_score' => 0,
            'kyc_status' => KycStatus::None,
            'tax_profile_complete' => false,
            'payout_method_set' => false,
        ];
    }

    /**
     * Bootstrap state — what CreatorBootstrapService creates: nothing
     * but user_id + status defaults. All optional/wizard fields null.
     */
    public function bootstrap(): static
    {
        return $this->state(fn (array $attributes): array => [
            'display_name' => null,
            'bio' => null,
            'country_code' => null,
            'region' => null,
            'primary_language' => null,
            'secondary_languages' => null,
            'categories' => null,
            'verification_level' => VerificationLevel::Unverified,
            'application_status' => ApplicationStatus::Incomplete,
            'profile_completeness_score' => 0,
            'kyc_status' => KycStatus::None,
            'tax_profile_complete' => false,
            'payout_method_set' => false,
        ]);
    }

    public function submitted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'application_status' => ApplicationStatus::Pending,
            'submitted_at' => now(),
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes): array => [
            'application_status' => ApplicationStatus::Approved,
            'submitted_at' => now()->subDays(2),
            'approved_at' => now(),
        ]);
    }
}
