<?php

declare(strict_types=1);

use App\Modules\Erasure\Execution\ErasureEngine;
use App\Modules\Erasure\Subject\SubjectSet;
use App\Modules\Strategy\Enums\CreatorDecisionStatus;
use App\Modules\Strategy\Models\WaveCreator;
use App\Modules\Strategy\Models\WaveCreatorDecision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ErasureGraph;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * PL-3.1 Q6 — erasing a creator scrubs the free text of EVERY client decision about them (note and comment), and nothing else:
 * the slot row stays with its creator reference ("Deleted creator"), the status, dates and client name stay, and another
 * creator's decisions are untouched.
 */

it('Q6: erasing a creator nulls the note and comment of every decision about them and leaves the rest', function (): void {
    $a = ErasureGraph::seedSubject('a');
    $b = ErasureGraph::seedSubject('b');
    $slotA = WaveCreator::query()->withoutGlobalScopes()->where('creator_id', $a->creator->id)->firstOrFail();
    $slotB = WaveCreator::query()->withoutGlobalScopes()->where('creator_id', $b->creator->id)->firstOrFail();
    $approved = WaveCreatorDecision::factory()->createOne(['wave_creator_id' => $slotA->id, 'status' => CreatorDecisionStatus::Approved, 'client_name' => 'Anna K.', 'note' => 'loved her tone']);
    $before = (array) DB::table('wave_creator_decisions')->where('id', $approved->id)->first();
    $bBefore = DB::table('wave_creator_decisions')->where('wave_creator_id', $slotB->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

    (new ErasureEngine)->run(SubjectSet::resolve($a->user));

    $after = DB::table('wave_creator_decisions')->where('wave_creator_id', $slotA->id)->orderBy('id')->get();
    expect($after)->toHaveCount(3)
        ->and($after->whereNotNull('note')->count())->toBe(0)
        ->and($after->whereNotNull('comment')->count())->toBe(0)
        ->and($after->pluck('client_name')->contains('Anna K.'))->toBeTrue()
        ->and($after->pluck('status')->sort()->values()->all())->toBe(['approved', 'approved', 'suggest_another'])
        ->and(DB::table('wave_creators')->where('id', $slotA->id)->value('creator_id'))->toBe($a->creator->id)
        ->and(DB::table('wave_creator_decisions')->where('wave_creator_id', $slotB->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all())->toBe($bBefore)
        ->and($before['note'])->toBe('loved her tone');
});
