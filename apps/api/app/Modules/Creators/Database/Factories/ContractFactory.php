<?php

declare(strict_types=1);

namespace App\Modules\Creators\Database\Factories;

use App\Modules\Creators\Enums\ContractKind;
use App\Modules\Creators\Enums\ContractStatus;
use App\Modules\Creators\Models\Contract;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contract>
 */
final class ContractFactory extends Factory
{
    protected $model = Contract::class;

    /**
     * Defaults to a click-through-shaped master agreement so tests get a
     * realistic flag-OFF acceptance record out of the box.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agency_id' => null,
            'kind' => ContractKind::MasterUniversal,
            'subject_type' => Contract::SUBJECT_CREATOR,
            'subject_id' => CreatorFactory::new(),
            'template_id' => null,
            'version' => 1,
            'title' => 'Master Creator Agreement',
            'body_markdown' => "# Master Creator Agreement\n\nTerms.",
            'signature_provider' => Contract::PROVIDER_INTERNAL,
            'status' => ContractStatus::Signed,
            'signed_at' => now(),
            'signed_signature_data' => [
                'method' => Contract::METHOD_CLICK_THROUGH,
                'version' => '1.0',
                'ip' => '203.0.113.10',
                'user_agent' => 'PHPUnit',
                'accepted_at' => now()->toIso8601String(),
            ],
        ];
    }
}
