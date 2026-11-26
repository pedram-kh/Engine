<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Creators\Models\Creator;
use App\Modules\Strategy\Features\CreatorsScriptsEnabled;
use App\Modules\Strategy\Features\StrategyEnabled;
use App\Modules\Strategy\Models\WaveCreator;
use App\Modules\Strategy\Models\WaveScript;
use App\Modules\Strategy\Models\WaveScriptBroll;
use App\Modules\Strategy\Models\WaveScriptHook;
use App\Modules\Strategy\Models\WaveScriptHookText;
use App\Modules\Strategy\Models\WaveScriptLine;
use App\Modules\Strategy\Models\WaveScriptLineText;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\Response;

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
        return array_values(WaveScriptHook::query()->where('script_id', $script->id)->orderBy('position')->get()->all());
    }

    /**
     * A script through the API (hooks A, B, C) and its ulid.
     *
     * @param  array<string, mixed>  $w
     */
    public static function api(array $w, string $market = 'SK', ?WaveCreator $slot = null): string
    {
        $slot ??= self::slot($w, $market)[0];

        return StrategyFixtures::call($w['admin'], 'POST', self::url($w), ['slot' => $slot->ulid])->assertCreated()->json('data.id');
    }

    /** A line on a script with the given position (and optionally English text and B-roll rows), written straight to the tables. */
    public static function line(WaveScript $script, int $position, ?string $english = null, int $broll = 0, bool $cta = false): WaveScriptLine
    {
        $line = WaveScriptLine::factory()->create(['script_id' => $script->id, 'agency_id' => $script->agency_id, 'wave_id' => $script->wave_id, 'position' => $position, 'cta' => $cta]);
        if ($english !== null) {
            self::lineText($line, $english);
        }
        for ($i = 0; $i < $broll; $i++) {
            WaveScriptBroll::query()->create(['agency_id' => $script->agency_id, 'wave_id' => $script->wave_id, 'script_id' => $script->id, 'line_id' => $line->id, 'position' => $i]);
        }

        return $line;
    }

    public static function lineText(WaveScriptLine $line, string $body, string $language = 'en'): WaveScriptLineText
    {
        return WaveScriptLineText::query()->create([
            'agency_id' => $line->agency_id, 'wave_id' => $line->wave_id, 'script_id' => $line->script_id, 'line_id' => $line->id,
            'language' => $language, 'body' => $body, 'origin' => 'manual',
        ]);
    }

    /**
     * Set a market's local languages through the API.
     *
     * @param  array<string, mixed>  $w
     * @param  list<string>  $languages
     * @return TestResponse<Response>
     */
    public static function languages(array $w, string $market, array $languages): TestResponse
    {
        return StrategyFixtures::call($w['admin'], 'PUT', StrategyFixtures::u($w, "markets/{$w['mk'][$market]}/script-languages"), ['languages' => $languages]);
    }

    /** Arm all three flags again: the thumbnail job runs on the sync queue in tests and Pennant forgets its in-memory state after a job. */
    public static function rearm(): void
    {
        WaveFixtures::armed();
        Feature::activate(StrategyEnabled::NAME);
        Feature::activate(CreatorsScriptsEnabled::NAME);
    }

    /**
     * init, store the bytes at the reserved key as the browser would, complete.
     *
     * @param  array<string, mixed>  $w
     * @return TestResponse<Response> the complete response
     */
    public static function upload(array $w, string $script, string $block, string $ownerUlid, string $bytes, string $mime = 'image/png', string $name = 'ref.png', ?int $declared = null): TestResponse
    {
        $init = StrategyFixtures::call($w['staff'], 'POST', self::url($w, "{$script}/images/uploads/init"), [
            'block' => $block, 'id' => $ownerUlid, 'mime_type' => $mime, 'declared_bytes' => $declared ?? strlen($bytes), 'original_filename' => $name,
        ])->assertCreated();
        Storage::disk('media')->put((string) $init->json('data.upload_id'), $bytes);
        $done = StrategyFixtures::call($w['staff'], 'POST', self::url($w, "{$script}/images/uploads/complete"), ['upload_id' => $init->json('data.upload_id')]);
        self::rearm();

        return $done;
    }

    public static function png(int $w = 40, int $h = 30): string
    {
        return ConceptFixtures::png($w, $h);
    }

    /** A GIF with TWO frames, 4 x 4 pixels (hand-assembled; verified to decode as two frames). */
    public static function animatedGif(): string
    {
        return (string) base64_decode('R0lGODlhBAAEAIAAAP8AAAAA/yH/C05FVFNDQVBFMi4wAwEAAAAh+QQACgAAACwAAAAABAAEAAACBISPCQUAIfkEAAoAAAAsAAAAAAQABAAAAgSMjxkFADs=', true);
    }
}
