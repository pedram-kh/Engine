<?php

declare(strict_types=1);

use App\Modules\Erasure\Execution\ErasureEngine;
use App\Modules\Erasure\Subject\SubjectSet;
use App\Modules\Strategy\Models\WaveCreator;
use App\Modules\Strategy\Models\WaveScriptImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\ErasureGraph;
use Tests\Support\ScriptFixtures as SF;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * PL-3.2a (G15) — erasing a creator keeps their scripts, hooks and texts exactly as they are (the three tables are decided
 * NotApplicable in the registry, tech-debt 48 carries the privacy sentence): the creator row is anonymised in place, so
 * `creator_id` still points at it and the rail reads "Deleted creator".
 *
 * PL-3.2b (Z2, ruling 5) — the lines, their texts, the B-roll rows and the reference images (rows AND stored objects) are kept
 * the same way. RESIDUAL RISK, written in the review and the Erasure decision: a reference image can show the erased creator.
 */

it('G15 and Z2: erasing a creator leaves their scripts, hooks, lines, B-roll, texts and images (and the stored objects) and every other creator\'s untouched', function (): void {
    Storage::fake('media');
    $a = ErasureGraph::seedSubject('a');
    $b = ErasureGraph::seedSubject('b');
    $slotA = WaveCreator::query()->withoutGlobalScopes()->where('creator_id', $a->creator->id)->firstOrFail();
    $slotB = WaveCreator::query()->withoutGlobalScopes()->where('creator_id', $b->creator->id)->firstOrFail();
    foreach ([$slotA, $slotB] as $slot) {
        $script = SF::script($slot);
        $script->forceFill(['angle' => 'Typed by the agency'])->save();
        SF::text(SF::hooksOf($script)[0], 'audio', 'Words for '.$slot->id);
        $line = SF::line($script, 0, 'Spoken for '.$slot->id, 2, true);
        SF::lineText($line, 'Hovorené', 'sk');
        $key = "agencies/a/brands/b/waves/w/scripts/{$script->ulid}/images/".Str::ulid().'.png';
        Storage::disk('media')->put($key, 'bytes');
        WaveScriptImage::query()->create([
            'ulid' => (string) Str::ulid(), 'agency_id' => $script->agency_id, 'wave_id' => $script->wave_id, 'script_id' => $script->id, 'line_id' => $line->id, 'position' => 0,
            'storage_key' => $key, 'original_filename' => 'face.png', 'declared_bytes' => 5, 'size_bytes' => 5, 'mime_type' => 'image/png', 'status' => 'ready',
        ]);
    }
    $snapshot = static fn (): array => [
        DB::table('wave_scripts')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        DB::table('wave_script_hooks')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        DB::table('wave_script_hook_texts')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        DB::table('wave_script_lines')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        DB::table('wave_script_line_texts')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        DB::table('wave_script_broll')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        DB::table('wave_script_images')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
    ];
    $before = $snapshot();
    expect($before[0])->toHaveCount(2)->and($before[3])->toHaveCount(2)->and($before[4])->toHaveCount(4)->and($before[5])->toHaveCount(4)->and($before[6])->toHaveCount(2);
    $objects = Storage::disk('media')->allFiles();
    expect($objects)->toHaveCount(2);

    (new ErasureEngine)->run(SubjectSet::resolve($a->user));

    expect($snapshot())->toBe($before)->and(Storage::disk('media')->allFiles())->toBe($objects)
        ->and(DB::table('wave_scripts')->where('wave_creator_id', $slotA->id)->value('creator_id'))->toBe($a->creator->id);
});
