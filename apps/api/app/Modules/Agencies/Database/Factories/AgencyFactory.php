<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Database\Factories;

use App\Modules\Agencies\Models\Agency;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Agency>
 */
final class AgencyFactory extends Factory
{
    protected $model = Agency::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->randomNumber(5),
            'country_code' => 'PT',
            'default_currency' => 'EUR',
            'default_language' => 'en',
            'subscription_tier' => 'pilot',
            'subscription_status' => 'active',
            'settings' => [],
            'is_active' => true,
        ];
    }

    /**
     * A suspended agency (Sprint 13, D-3). `suspended_at` is the SOT
     * marker the auth-layer login block reads; `is_active=false` is kept
     * in lock-step.
     */
    public function suspended(string $reason = 'Suspended for testing purposes.'): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
            'suspended_at' => now(),
            'suspended_reason' => $reason,
        ]);
    }

    /**
     * The Catalyst pilot tenant (docs/20-PHASE-1-SPEC.md §1).
     */
    public function catalyst(): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => 'Catalyst',
            'slug' => 'catalyst',
            'country_code' => 'PT',
            'default_currency' => 'EUR',
            'default_language' => 'en',
            'subscription_tier' => 'pilot',
            'subscription_status' => 'active',
        ]);
    }
}
