<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Database\Factories;

use App\Modules\Strategy\Enums\MatrixLevel;
use App\Modules\Strategy\Models\WaveMatrixCell;
use App\Modules\Strategy\Models\WavePersona;
use App\Modules\Strategy\Models\WaveUsp;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WaveMatrixCell>
 */
final class WaveMatrixCellFactory extends Factory
{
    protected $model = WaveMatrixCell::class;

    /**
     * The persona defaults to a new one; the USP to a new one of the SAME wave; the market is the persona's.
     * (Creating a cell through the factory does NOT sync an angle: use MatrixService for that.)
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
            'usp_id' => fn (array $a) => WaveUsp::factory()->create(['wave_id' => $a['wave_id'], 'agency_id' => $a['agency_id']])->id,
            'level' => MatrixLevel::Priority,
            'rationale' => 'Because it matters to them',
            'message_line' => 'Lead with this',
            'source' => 'manual',
        ];
    }
}
