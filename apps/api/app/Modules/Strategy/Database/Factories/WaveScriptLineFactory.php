<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Database\Factories;

use App\Modules\Strategy\Models\WaveScript;
use App\Modules\Strategy\Models\WaveScriptLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WaveScriptLine>
 */
final class WaveScriptLineFactory extends Factory
{
    protected $model = WaveScriptLine::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'script_id' => fn () => WaveScript::factory()->create()->id,
            'wave_id' => fn (array $a) => (int) WaveScript::withoutGlobalScopes()->whereKey($a['script_id'])->value('wave_id'),
            'agency_id' => fn (array $a) => (int) WaveScript::withoutGlobalScopes()->whereKey($a['script_id'])->value('agency_id'),
            'position' => fn (array $a) => WaveScriptLine::withoutGlobalScopes()->where('script_id', $a['script_id'])->count(),
            'cta' => false,
            'revision' => 1,
        ];
    }
}
