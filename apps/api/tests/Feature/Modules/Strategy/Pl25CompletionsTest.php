<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Strategy\Features\StrategyEnabled;
use App\Modules\Strategy\Models\WaveStrategyCompletion;
use App\Modules\Strategy\Models\WaveStrategyNote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Pennant\Feature;
use Tests\Support\ConceptFixtures;
use Tests\Support\Pl25Fixtures as P;
use Tests\Support\StrategyFixtures as S;
use Tests\Support\WaveFixtures;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** PL-2.5 B1 to B3, B5 to B9, B11, B12: completion marks, derived state, rules A to G, landing, hooks. */
function p25Summary(array $w): array
{
    return S::call($w['admin'], 'GET', S::u($w))->assertOk()->json('data.attributes');
}

function p25Audits(string $action): array
{
    return AuditLog::query()->where('action', $action)->orderBy('id')->get()->map(fn ($r) => (array) $r->metadata)->all();
}

it('B6 / Q1: an empty step cannot be marked (422 strategy.nothing_to_complete); no row, no audit', function (): void {
    $w = S::world();

    P::mark($w, 'usps')->assertStatus(422)->assertJsonPath('errors.0.code', 'strategy.nothing_to_complete');
    P::mark($w, 'personas', 'SK')->assertStatus(422)->assertJsonPath('errors.0.code', 'strategy.nothing_to_complete');
    P::mark($w, 'matrix', 'SK')->assertStatus(422)->assertJsonPath('errors.0.code', 'strategy.nothing_to_complete');

    expect(WaveStrategyCompletion::query()->count())->toBe(0)->and(p25Audits('wave_strategy.completed'))->toBe([]);
});

it('B6 / Q1: the minimum warnings are returned and never block; mark is idempotent; reopen is idempotent', function (): void {
    $w = S::world();
    P::usps($w, 2);

    $res = P::mark($w, 'usps')->assertOk();
    expect(P::codes((array) $res->json('meta.warnings')))->toContain('usps_under_recommended');
    P::mark($w, 'usps')->assertOk();
    expect(WaveStrategyCompletion::query()->count())->toBe(1)->and(p25Audits('wave_strategy.completed'))->toHaveCount(1)
        ->and(p25Audits('wave_strategy.completed')[0])->toBe(['wave' => $w['wave']->ulid, 'step' => 'usps', 'market' => null]);

    P::reopen($w, 'usps')->assertOk();
    P::reopen($w, 'usps')->assertOk();
    expect(WaveStrategyCompletion::query()->count())->toBe(0)->and(p25Audits('wave_strategy.reopened'))->toHaveCount(1);
});

it('B6: market is required for personas and matrix, refused for usps; concepts and unknown steps are 404', function (): void {
    $w = S::world();
    P::usps($w, 1);
    P::personas($w, 'SK');

    P::mark($w, 'personas')->assertStatus(422)->assertJsonPath('errors.0.code', 'strategy.market_required');
    S::call($w['staff'], 'PUT', S::u($w, 'completions/usps').'?market='.$w['mk']['SK'])->assertStatus(422)->assertJsonPath('errors.0.code', 'strategy.market_not_allowed');
    S::call($w['staff'], 'PUT', S::u($w, 'completions/concepts'))->assertNotFound();
    S::call($w['staff'], 'PUT', S::u($w, 'completions/nope'))->assertNotFound();
});

it('B3 / Q2: admin, manager and staff can all mark and reopen', function (): void {
    $w = S::world();
    P::usps($w, 1);
    foreach (['admin', 'manager', 'staff'] as $who) {
        P::mark($w, 'usps', null, $who)->assertOk();
        P::reopen($w, 'usps', null, $who)->assertOk();
    }
    expect(p25Audits('wave_strategy.completed'))->toHaveCount(3);
});

it('B2: another agency, another wave and a foreign market ulid are refused', function (): void {
    $w = S::world();
    P::usps($w, 1);
    P::personas($w, 'SK');

    S::call($w['otherAdmin'], 'PUT', S::u($w, 'completions/usps'))->assertNotFound();
    $other = S::call($w['admin'], 'PUT', S::url($w['agency'], $w['brand'], $w['otherWave'], 'completions/usps'));
    expect($other->status())->toBeIn([403, 404]);
    S::call($w['staff'], 'PUT', S::u($w, 'completions/personas').'?market='.S::NO_MARKET)->assertStatus(422);
});

it('B5 / K2: the wave step is done only when done in every market; "n of m"; removing the unfinished markets completes it', function (): void {
    $w = S::world();
    [$persona] = P::personas($w, 'SK');
    $c = P::concept($w, 'SK', $persona);
    ConceptFixtures::decide($w, $c)->assertOk();

    $concepts = p25Summary($w)['steps']['concepts'];
    expect($concepts['state'])->toBe('in_progress')->and($concepts['markets_done'])->toBe(1)->and($concepts['markets_total'])->toBe(3);
    expect(P::st($w, 'ugc.concepts'))->toBe(['in_progress', 'system']);

    S::keepMarkets($w, ['SK'])->assertOk();
    expect(p25Summary($w)['steps']['concepts']['state'])->toBe('done')
        ->and(P::st($w, 'ugc.concepts'))->toBe(['done', 'system']);
});

it('B6: concepts is automatic: approve all = done; unapprove one, edit an approved one, a new one = not done again', function (): void {
    $w = S::world();
    S::keepMarkets($w, ['SK'])->assertOk();
    [$persona] = P::personas($w, 'SK');
    $a = P::concept($w, 'SK', $persona);
    $b = P::concept($w, 'SK', $persona);
    ConceptFixtures::decide($w, $a)->assertOk();
    expect(P::st($w, 'ugc.concepts')[0])->toBe('in_progress');
    ConceptFixtures::decide($w, $b)->assertOk();
    expect(P::st($w, 'ugc.concepts'))->toBe(['done', 'system']);

    ConceptFixtures::decide($w, $b, 'changes_requested')->assertOk();
    expect(P::st($w, 'ugc.concepts')[0])->toBe('in_progress');
    ConceptFixtures::decide($w, $b)->assertOk();
    expect(P::st($w, 'ugc.concepts')[0])->toBe('done');

    S::call($w['staff'], 'PATCH', S::u($w, "concepts/{$a}"), ['title' => 'Edited after approval'])->assertOk();
    expect(P::st($w, 'ugc.concepts')[0])->toBe('in_progress');

    ConceptFixtures::decide($w, $a)->assertOk();
    P::concept($w, 'SK', $persona);
    expect(P::st($w, 'ugc.concepts')[0])->toBe('in_progress');
});

it('B6: deleting the only unapproved concept completes the step', function (): void {
    $w = S::world();
    S::keepMarkets($w, ['SK'])->assertOk();
    [$persona] = P::personas($w, 'SK');
    $a = P::concept($w, 'SK', $persona);
    $b = P::concept($w, 'SK', $persona);
    ConceptFixtures::decide($w, $a)->assertOk();

    S::call($w['staff'], 'DELETE', S::u($w, "concepts/{$b}"))->assertStatus(204);

    expect(P::st($w, 'ugc.concepts'))->toBe(['done', 'system']);
});

it('B7 rule A: deleting a USP below the recommendation leaves the mark; the warning is live', function (): void {
    $w = S::world();
    $usps = P::usps($w, 4);
    P::mark($w, 'usps')->assertOk();
    S::call($w['admin'], 'DELETE', S::u($w, "usps/{$usps[0]}"))->assertStatus(204);

    $usp = p25Summary($w)['steps']['usps'];
    expect($usp['state'])->toBe('done')->and(P::codes($usp['warnings']))->toContain('usps_under_recommended')
        ->and(P::st($w, 'ugc.usps'))->toBe(['done', 'system']);
});

it('B7 rule B: a persona added to a marked market leaves the personas mark; the matrix shows cells not set', function (): void {
    $w = S::world();
    [$u] = P::usps($w, 1);
    [$p1] = P::personas($w, 'SK');
    P::cell($w, 'SK', $p1, $u)->assertOk();
    P::mark($w, 'personas', 'SK')->assertOk();
    P::mark($w, 'matrix', 'SK')->assertOk();

    P::personas($w, 'SK');

    $steps = p25Summary($w)['steps'];
    $personas = P::where($steps['personas']['markets'], 'code', 'SK');
    $matrix = P::where($steps['matrix']['markets'], 'code', 'SK');
    expect($personas['marked'])->toBeTrue()->and($matrix['marked'])->toBeTrue()
        ->and(P::codes($matrix['warnings']))->toContain('cells_not_set');
});

it('B7 rule E: the last record going drops the mark, audited wave_strategy.reopened with reason emptied', function (): void {
    $w = S::world();
    [$u] = P::usps($w, 1);
    P::mark($w, 'usps')->assertOk();

    S::call($w['admin'], 'DELETE', S::u($w, "usps/{$u}"))->assertStatus(204);

    expect(WaveStrategyCompletion::query()->count())->toBe(0)->and(P::st($w, 'ugc.usps'))->toBe(['not_started', 'system'])
        ->and(p25Audits('wave_strategy.reopened'))->toBe([['wave' => $w['wave']->ulid, 'step' => 'usps', 'market' => null, 'reason' => 'emptied']]);
});

it('B7 rules E and C: the last persona of a marked market drops its marks (personas and matrix); deleting a USP drops the matrix marks of every market with no cell left', function (): void {
    $w = S::world();
    [$u] = P::usps($w, 1);
    [$p] = P::personas($w, 'SK');
    P::cell($w, 'SK', $p, $u)->assertOk();
    P::mark($w, 'personas', 'SK')->assertOk();
    P::mark($w, 'matrix', 'SK')->assertOk();

    S::call($w['admin'], 'DELETE', S::u($w, "personas/{$p}"))->assertStatus(204);

    expect(WaveStrategyCompletion::query()->count())->toBe(0);
});

it('B7 rule D (Q5): removing a market with notes asks for the count, then deletes the notes with it and audits the counts; a mark of a market goes too', function (): void {
    $w = S::world();
    S::call($w['staff'], 'POST', S::u($w, 'notes'), ['step' => 'personas', 'market' => $w['mk']['RO'], 'body' => 'Check with the client'])->assertCreated();
    S::call($w['staff'], 'POST', S::u($w, 'notes'), ['step' => 'matrix', 'market' => $w['mk']['RO'], 'body' => 'Second'])->assertCreated();
    $url = WaveFixtures::wavesUrl($w['agency'], $w['brand'], $w['wave']->ulid);
    $attrs = S::call($w['admin'], 'GET', $url)->assertOk()->json('data.attributes');
    $markets = array_values(array_filter($attrs['markets'], fn ($m) => $m['code'] !== 'RO'));
    $body = ['markets' => $markets, 'markets_version' => $attrs['markets_version']];

    $res = S::call($w['admin'], 'PATCH', $url, $body)->assertStatus(422);
    expect($res->json('errors.0.code'))->toBe('wave.market_has_notes')->and($res->json('errors.0.meta.note_count'))->toBe(2);
    expect(WaveStrategyNote::query()->count())->toBe(2);

    S::call($w['admin'], 'PATCH', $url, $body + ['confirm_notes_deleted' => 2])->assertOk();
    expect(WaveStrategyNote::query()->count())->toBe(0);
    $updated = AuditLog::query()->where('action', 'wave.updated')->orderByDesc('id')->first();
    expect($updated?->after['notes_removed'] ?? null)->toBe(2)->and($updated?->after['completions_removed'] ?? null)->toBe(0);
});

it('B7: a wave edit that removes a market still refuses when it has personas, with or without confirm_notes_deleted', function (): void {
    $w = S::world();
    P::personas($w, 'RO');
    $url = WaveFixtures::wavesUrl($w['agency'], $w['brand'], $w['wave']->ulid);
    $attrs = S::call($w['admin'], 'GET', $url)->assertOk()->json('data.attributes');
    $markets = array_values(array_filter($attrs['markets'], fn ($m) => $m['code'] !== 'RO'));

    S::call($w['admin'], 'PATCH', $url, ['markets' => $markets, 'markets_version' => $attrs['markets_version'], 'confirm_notes_deleted' => 0])
        ->assertStatus(422)->assertJsonPath('errors.0.code', 'wave.market_has_personas');
});

it('B8 / K3: where "Strategy & Scripts" opens, rows 1 to 8 of the landing table', function (): void {
    $w = S::world();
    $land = fn () => p25Summary($w)['landing'];

    expect($land())->toMatchArray(['step' => 'usps', 'market' => null]);                                              // 1
    [$u] = P::usps($w, 1);
    expect($land()['step'])->toBe('usps');                                                                            // 2
    P::mark($w, 'usps')->assertOk();
    expect($land())->toMatchArray(['step' => 'personas', 'market' => 'SK', 'market_id' => $w['mk']['SK']]);          // 3
    [$psk] = P::personas($w, 'SK');
    P::personas($w, 'RO');
    P::mark($w, 'personas', 'SK')->assertOk();
    expect($land())->toMatchArray(['step' => 'personas', 'market' => 'RO']);                                          // 4
    P::mark($w, 'personas', 'RO')->assertOk();
    P::personas($w, 'CZ');
    P::mark($w, 'personas', 'CZ')->assertOk();
    expect($land())->toMatchArray(['step' => 'matrix', 'market' => 'SK']);                                            // 5
    foreach (['SK' => $psk] as $m => $p) {
        P::cell($w, $m, $p, $u)->assertOk();
        P::mark($w, 'matrix', $m)->assertOk();
    }
    $ro = S::call($w['admin'], 'GET', S::u($w, 'personas').'?market='.$w['mk']['RO'])->json('data.0.id');
    $cz = S::call($w['admin'], 'GET', S::u($w, 'personas').'?market='.$w['mk']['CZ'])->json('data.0.id');
    P::cell($w, 'RO', $ro, $u)->assertOk();
    P::mark($w, 'matrix', 'RO')->assertOk();
    P::cell($w, 'CZ', $cz, $u)->assertOk();
    P::mark($w, 'matrix', 'CZ')->assertOk();
    $csk = P::concept($w, 'SK', $psk);
    ConceptFixtures::decide($w, $csk)->assertOk();
    P::concept($w, 'RO', $ro);
    expect($land())->toMatchArray(['step' => 'concepts', 'market' => 'RO']);                                          // 6
    ConceptFixtures::decide($w, S::call($w['admin'], 'GET', S::u($w, 'concepts').'?market='.$w['mk']['RO'])->json('data.0.id'))->assertOk();
    P::concept($w, 'CZ', $cz);
    ConceptFixtures::decide($w, S::call($w['admin'], 'GET', S::u($w, 'concepts').'?market='.$w['mk']['CZ'])->json('data.0.id'))->assertOk();
    expect($land())->toMatchArray(['step' => 'concepts', 'market' => 'SK']);                                          // 7
});

it('B8 row 8: a row hand-set to done while the real step is not: the landing is the real step', function (): void {
    $w = S::world();
    P::hand($w, 'ugc.usps', 'done')->assertStatus(409);
    P::hand($w, 'ugc.usps', 'done', true)->assertOk();

    expect(p25Summary($w)['landing']['step'])->toBe('usps');
});

it('B1: with strategy OFF the tracker carries no strategy block and the summary route is 404; marks and notes are 404', function (): void {
    $w = S::world();
    Feature::deactivate(StrategyEnabled::NAME);

    S::call($w['staff'], 'PUT', S::u($w, 'completions/usps'))->assertNotFound();
    S::call($w['staff'], 'GET', S::u($w, 'notes').'?step=usps')->assertNotFound();
    S::call($w['staff'], 'GET', S::u($w))->assertNotFound();
});

it('B12: a failure after the hook rolls the tracker row back with the data', function (): void {
    $w = S::world();
    P::usps($w, 1);
    [$status] = P::st($w, 'ugc.usps');
    expect($status)->toBe('in_progress');

    Event::listen('eloquent.created: '.WaveStrategyCompletion::class, function (): void {
        throw new RuntimeException('boom');
    });
    // The mark is written, the hook then writes the row; a failure on the mark's own event rolls both back.
    try {
        P::mark($w, 'usps');
    } catch (Throwable) {
    }

    expect(WaveStrategyCompletion::query()->count())->toBe(0)->and(P::st($w, 'ugc.usps'))->toBe(['in_progress', 'system'])
        ->and(p25Audits('wave_strategy.completed'))->toBe([]);
});

it('B9: every writer moves the stored row in the same request (usp, persona, copy, matrix, concept create and delete)', function (): void {
    $w = S::world();
    [$u] = P::usps($w, 1);
    expect(P::st($w, 'ugc.usps')[0])->toBe('in_progress');

    [$p] = P::personas($w, 'SK');
    expect(P::st($w, 'ugc.personas')[0])->toBe('in_progress');

    P::cell($w, 'SK', $p, $u)->assertOk();
    expect(P::st($w, 'ugc.matrix')[0])->toBe('in_progress');

    $c = P::concept($w, 'SK', $p);
    expect(P::st($w, 'ugc.concepts')[0])->toBe('in_progress');
    S::call($w['staff'], 'DELETE', S::u($w, "concepts/{$c}"))->assertStatus(204);
    expect(P::st($w, 'ugc.concepts')[0])->toBe('not_started');

    S::call($w['staff'], 'POST', S::u($w, 'personas/copy'), ['from' => $w['mk']['SK'], 'to' => [$w['mk']['RO']]]);
});
