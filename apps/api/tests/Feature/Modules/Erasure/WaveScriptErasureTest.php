<?php

declare(strict_types=1);

use App\Modules\Erasure\Execution\ErasureEngine;
use App\Modules\Erasure\Subject\SubjectSet;
use App\Modules\Strategy\Models\WaveCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ErasureGraph;
use Tests\Support\ScriptFixtures as SF;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * PL-3.2a (G15) — erasing a creator keeps their scripts, hooks and texts exactly as they are (the three tables are decided
 * NotApplicable in the registry, tech-debt 48 carries the privacy sentence): the creator row is anonymised in place, so
 * `creator_id` still points at it and the rail reads "Deleted creator".
 */

it('G15: erasing a creator leaves their scripts, hooks and texts and every other creator\'s untouched', function (): void {
    $a = ErasureGraph::seedSubject('a');
    $b = ErasureGraph::seedSubject('b');
    $slotA = WaveCreator::query()->withoutGlobalScopes()->where('creator_id', $a->creator->id)->firstOrFail();
    $slotB = WaveCreator::query()->withoutGlobalScopes()->where('creator_id', $b->creator->id)->firstOrFail();
    foreach ([$slotA, $slotB] as $slot) {
        $script = SF::script($slot);
        $script->forceFill(['angle' => 'Typed by the agency'])->save();
        SF::text(SF::hooksOf($script)[0], 'audio', 'Words for '.$slot->id);
    }
    $snapshot = static fn (): array => [
        DB::table('wave_scripts')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        DB::table('wave_script_hooks')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        DB::table('wave_script_hook_texts')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
    ];
    $before = $snapshot();
    expect($before[0])->toHaveCount(2);

    (new ErasureEngine)->run(SubjectSet::resolve($a->user));

    expect($snapshot())->toBe($before)
        ->and(DB::table('wave_scripts')->where('wave_creator_id', $slotA->id)->value('creator_id'))->toBe($a->creator->id);
});
