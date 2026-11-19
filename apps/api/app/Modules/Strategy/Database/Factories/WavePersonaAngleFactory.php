<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Database\Factories;

use App\Modules\Strategy\Enums\AngleOrigin;
use App\Modules\Strategy\Models\WavePersona;
use App\Modules\Strategy\Models\WavePersonaAngle;
use App\Modules\Strategy\Models\WaveUsp;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WavePersonaAngle>
 */
final class WavePersonaAngleFactory extends Factory
{
    protected $model = WavePersonaAngle::class;

    /**
     * The persona defaults to a new one; the USP to a new one of the SAME wave.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'persona_id' => fn () => WavePersona::factory()->create()->id,
            'wave_id' => fn (array $a) => (int) WavePersona::withoutGlobalScopes()->whereKey($a['persona_id'])->value('wave_id'),
            'agency_id' => fn (array $a) => (int) WavePersona::withoutGlobalScopes()->whereKey($a['persona_id'])->value('agency_id'),
            'usp_id' => fn (array $a) => WaveUsp::factory()->create(['wave_id' => $a['wave_id'], 'agency_id' => $a['agency_id']])->id,
            'explanation' => 'Because it matters to them',
            'origin' => AngleOrigin::Manual,
        ];
    }
}
