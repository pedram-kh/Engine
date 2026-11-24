<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Creators\Models\Creator;
use App\Modules\Strategy\Models\WaveCreator;
use App\Modules\Strategy\Models\WaveScript;
use App\Modules\Strategy\Models\WaveScriptHook;
use App\Modules\Strategy\Models\WaveScriptHookText;

/**
 * PL-3.2a — fixtures for the scripts specs: the creators world (all three flags on), a slot with a creator, a script with its
 * three hooks, and the URL helper.
 */
final class ScriptFixtures
{
    /** @return array<string, mixed> a CreatorFixtures::world() result */
    public static function world(bool $armed = true): array
    {
        return CreatorFixtures::world($armed);
    }

    /**
     * @param  array<string, mixed>  $w
     * @return array{0: WaveCreator, 1: Creator}
     */
    public static function slot(array $w, string $market = 'SK', array $extra = []): array
    {
        $creator = CreatorFixtures::applicant($w['campaign']);

        return [CreatorFixtures::slot($w, $creator, $market, $extra), $creator];
    }

    /** A script on the slot with hooks A, B, C (as the service creates it). */
    public static function script(WaveCreator $slot, array $extra = [], int $hooks = 3): WaveScript
    {
        $script = WaveScript::factory()->create($extra + ['wave_creator_id' => $slot->id]);
        for ($i = 0; $i < $hooks; $i++) {
            WaveScriptHook::factory()->create(['script_id' => $script->id, 'position' => $i]);
        }

        return $script;
    }

    public static function text(WaveScriptHook $hook, string $field, string $body, string $language = 'en'): WaveScriptHookText
    {
        return WaveScriptHookText::query()->create([
            'agency_id' => $hook->agency_id, 'wave_id' => $hook->wave_id, 'script_id' => $hook->script_id, 'hook_id' => $hook->id,
            'field' => $field, 'language' => $language, 'body' => $body, 'origin' => 'manual',
        ]);
    }

    /** @param  array<string, mixed>  $w */
    public static function url(array $w, string $suffix = ''): string
    {
        return StrategyFixtures::u($w, 'scripts'.($suffix !== '' ? '/'.$suffix : ''));
    }

    /** @return list<WaveScriptHook> */
    public static function hooksOf(WaveScript $script): array
    {
        return WaveScriptHook::query()->where('script_id', $script->id)->orderBy('position')->get()->all();
    }
}
