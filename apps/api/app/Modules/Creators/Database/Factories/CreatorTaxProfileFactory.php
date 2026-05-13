<?php

declare(strict_types=1);

namespace App\Modules\Creators\Database\Factories;

use App\Modules\Creators\Enums\TaxFormType;
use App\Modules\Creators\Models\CreatorTaxProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreatorTaxProfile>
 */
final class CreatorTaxProfileFactory extends Factory
{
    protected $model = CreatorTaxProfile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'creator_id' => CreatorFactory::new(),
            'legal_name' => fake()->name(),
            'tax_form_type' => TaxFormType::EuSelfEmployed,
            'tax_id' => 'IT'.fake()->numerify('###########'),
            'tax_id_country' => 'IT',
            'address' => [
                'line1' => fake()->streetAddress(),
                'city' => fake()->city(),
                'postal_code' => fake()->postcode(),
                'country' => 'IT',
            ],
            'submitted_at' => now(),
            'verified_at' => null,
        ];
    }
}
