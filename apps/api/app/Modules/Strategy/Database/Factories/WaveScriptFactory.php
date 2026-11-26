<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Database\Factories;

use App\Modules\Strategy\Models\WaveCreator;
use App\Modules\Strategy\Models\WaveScript;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A script of a slot. Pass `wave_creator_id` (a WaveCreator id); the wave, market, agency and creator follow from the slot.
 * With no hooks (the service creates A, B, C; a factory script has none unless a test adds them).
 *
 * @extends Factory<WaveScript>
 */
final class WaveScriptFactory extends Factory
{
    protected $model = WaveScript::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'wave_creator_id' => fn () => WaveCreator::factory()->create()->id,
            'wave_id' => fn (array $a) => self::slot($a)->wave_id,
            'agency_id' => fn (array $a) => self::slot($a)->agency_id,
            'market_id' => fn (array $a) => self::slot($a)->market_id,
            'creator_id' => fn (array $a) => self::slot($a)->creator_id,
            'number' => fn (array $a) => self::freeNumber(self::slot($a)),
            'angle' => null,
            'duration_seconds' => 30,
            'revision' => 1,
            'brief_revision' => 1,
        ];
    }

    /** An archived script: no slot, archived now. */
    public function archived(): self
    {
        return $this->state(['wave_creator_id' => null, 'archived_at' => now()]);
    }

    /** @param  array<string, mixed>  $a */
    private static function slot(array $a): WaveCreator
    {
        return WaveCreator::withoutGlobalScopes()->whereKey((int) $a['wave_creator_id'])->firstOrFail();
    }

    private static function freeNumber(WaveCreator $slot): int
    {
        $used = WaveScript::withoutGlobalScopes()->where('wave_id', $slot->wave_id)->where('creator_id', $slot->creator_id)->pluck('number')->all();
        for ($n = 1; $n <= 3; $n++) {
            if (! in_array($n, $used, true)) {
                return $n;
            }
        }

        return 3;
    }
}
