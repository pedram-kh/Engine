<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Database\Factories;

use App\Modules\Strategy\Models\WaveScript;
use App\Modules\Strategy\Models\WaveScriptHook;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WaveScriptHook>
 */
final class WaveScriptHookFactory extends Factory
{
    protected $model = WaveScriptHook::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'script_id' => fn () => WaveScript::factory()->create()->id,
            'wave_id' => fn (array $a) => (int) WaveScript::withoutGlobalScopes()->whereKey($a['script_id'])->value('wave_id'),
            'agency_id' => fn (array $a) => (int) WaveScript::withoutGlobalScopes()->whereKey($a['script_id'])->value('agency_id'),
            'position' => fn (array $a) => WaveScriptHook::withoutGlobalScopes()->where('script_id', $a['script_id'])->count(),
            'shot_type' => null,
            'visual_description' => null,
            'revision' => 1,
        ];
    }
}
