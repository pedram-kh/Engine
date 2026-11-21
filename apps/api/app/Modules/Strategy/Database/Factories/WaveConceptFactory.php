<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Database\Factories;

use App\Modules\Strategy\Enums\ConceptOrigin;
use App\Modules\Strategy\Enums\ConceptStatus;
use App\Modules\Strategy\Models\WaveConcept;
use App\Modules\Strategy\Models\WavePersona;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WaveConcept>
 */
final class WaveConceptFactory extends Factory
{
    protected $model = WaveConcept::class;

    /**
     * The persona defines wave, market and agency; the position is the next free one of the market.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'persona_id' => fn () => WavePersona::factory()->create()->id,
            'wave_id' => fn (array $a) => (int) WavePersona::withoutGlobalScopes()->whereKey($a['persona_id'])->value('wave_id'),
            'agency_id' => fn (array $a) => (int) WavePersona::withoutGlobalScopes()->whereKey($a['persona_id'])->value('agency_id'),
            'market_id' => fn (array $a) => (int) WavePersona::withoutGlobalScopes()->whereKey($a['persona_id'])->value('market_id'),
            'position' => fn (array $a) => 1 + (int) WaveConcept::withoutGlobalScopes()->where('wave_id', $a['wave_id'])->where('market_id', $a['market_id'])->max('position'),
            'title' => 'Concept '.fake()->unique()->numberBetween(1, 999999),
            'status' => ConceptStatus::Awaiting,
            'origin' => ConceptOrigin::Manual,
        ];
    }
}
