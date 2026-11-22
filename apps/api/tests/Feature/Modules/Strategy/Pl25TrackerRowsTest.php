<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Strategy\Features\StrategyEnabled;
use App\Modules\Waves\Contracts\WaveStrategyState;
use App\Modules\Waves\Models\WaveStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Tests\Support\ConceptFixtures;
use Tests\Support\Pl25Fixtures as P;
use Tests\Support\StrategyFixtures as S;
use Tests\Support\WaveFixtures;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * PL-2.5 B4: the F1 table, one test per row (1 to 11), with the audit (reason, source, actor) and the 409 contract.
 * Real state R, hand-set state H. Rows are `ugc.usps` unless the row needs "n of m markets" (personas).
 */

function p25Audit(string $reason): ?array
{
    $rows = P::stepAudits($reason);

    return $rows === [] ? null : $rows[array_key_last($rows)];
}

it('F1 row 1: a row nobody set follows the real step on every change, source system, audited with the actor', function (): void {
    $w = S::world();
    P::usps($w, 1);
    expect(P::st($w, 'ugc.usps'))->toBe(['in_progress', 'system']);

    P::mark($w, 'usps')->assertOk();
    expect(P::st($w, 'ugc.usps'))->toBe(['done', 'system']);

    P::reopen($w, 'usps')->assertOk();
    expect(P::st($w, 'ugc.usps'))->toBe(['in_progress', 'system']);

    $audit = AuditLog::query()->where('action', 'wave_step.updated')->orderByDesc('id')->first();
    expect($audit?->actor_id)->toBe($w['staff']->id)->and(P::meta($audit)['reason'] ?? null)->toBe('system');
});

it('F1 row 2: a hand-set beyond the real step is refused 409 step.incomplete until confirmed, then manual, with the live figures', function (): void {
    $w = S::world();
    P::personas($w, 'SK');
    P::personas($w, 'RO');
    P::mark($w, 'personas', 'SK')->assertOk();

    $res = P::hand($w, 'ugc.personas', 'done')->assertStatus(409);
    expect($res->json('errors.0.code'))->toBe('step.incomplete')
        ->and($res->json('errors.0.meta'))->toMatchArray(['real_state' => 'in_progress', 'markets_done' => 1, 'markets_total' => 3]);
    expect(P::st($w, 'ugc.personas'))->toBe(['in_progress', 'system']);

    P::hand($w, 'ugc.personas', 'done', true)->assertOk();
    expect(P::st($w, 'ugc.personas'))->toBe(['done', 'manual']);

    $tracker = S::call($w['admin'], 'GET', WaveFixtures::wavesUrl($w['agency'], $w['brand'], $w['wave']->ulid.'/tracker'))->assertOk();
    $row = P::where(array_map(fn (array $r): array => $r['attributes'] + ['id' => $r['id']], (array) $tracker->json('data.attributes.steps')), 'key', 'ugc.personas');
    expect($row['strategy'])->toMatchArray(['state' => 'in_progress', 'markets_done' => 1, 'markets_total' => 3, 'hand_set_beyond' => true]);
});

it('F1 row 3: a hand-set equal to the real step is recorded as system (a manual row set equal to the data goes back to system)', function (): void {
    $w = S::world();
    P::usps($w, 1);
    P::hand($w, 'ugc.usps', 'done', true)->assertOk();
    expect(P::st($w, 'ugc.usps'))->toBe(['done', 'manual']);

    P::hand($w, 'ugc.usps', 'in_progress')->assertOk();   // equal to the real state (one USP, not marked)

    expect(P::st($w, 'ugc.usps'))->toBe(['in_progress', 'system']);
});

it('F1 row 4: a hand-set below the real step is manual, with no warning and no confirm, and is kept while nothing changes', function (): void {
    $w = S::world();
    P::usps($w, 1);
    P::mark($w, 'usps')->assertOk();
    P::hand($w, 'ugc.usps', 'in_progress')->assertOk();
    expect(P::st($w, 'ugc.usps'))->toBe(['in_progress', 'manual']);

    // A write that does not change the real state (a second USP, still marked) leaves it alone.
    P::usps($w, 1);
    expect(P::st($w, 'ugc.usps'))->toBe(['in_progress', 'manual']);
});

it('F1 row 5: a manual row beyond the real step stays manual while the real step changes but stays below it', function (): void {
    $w = S::world();
    P::personas($w, 'SK');
    P::personas($w, 'RO');
    P::hand($w, 'ugc.personas', 'done', true)->assertOk();

    P::mark($w, 'personas', 'SK')->assertOk();   // 0 of 3 to 1 of 3: the figures move, the state stays in_progress
    P::mark($w, 'personas', 'RO')->assertOk();   // 2 of 3

    expect(P::st($w, 'ugc.personas'))->toBe(['done', 'manual']);
    $tracker = S::call($w['admin'], 'GET', WaveFixtures::wavesUrl($w['agency'], $w['brand'], $w['wave']->ulid.'/tracker'))->assertOk();
    $row = P::where(array_map(fn (array $r): array => $r['attributes'] + ['id' => $r['id']], (array) $tracker->json('data.attributes.steps')), 'key', 'ugc.personas');
    expect($row['strategy']['markets_done'])->toBe(2);
});

it('F1 row 6: a manual row is taken back to system when the real step reaches the hand-set status, audited system_took_back', function (): void {
    $w = S::world();
    P::personas($w, 'SK');
    P::hand($w, 'ugc.personas', 'done', true)->assertOk();
    S::keepMarkets($w, ['SK'])->assertOk();

    P::mark($w, 'personas', 'SK')->assertOk();   // every market (one) is done: the real step reaches done

    expect(P::st($w, 'ugc.personas'))->toBe(['done', 'system']);
    $audit = p25Audit('system_took_back');
    expect($audit)->not->toBeNull();
    $row = AuditLog::query()->where('action', 'wave_step.updated')->orderByDesc('id')->first();
    expect($row?->actor_id)->toBe($w['staff']->id);
});

it('F1 row 7: a manual row beyond the real step stays when the real step falls', function (): void {
    $w = S::world();
    [$usp] = P::usps($w, 1);
    P::hand($w, 'ugc.usps', 'done', true)->assertOk();
    expect(P::st($w, 'ugc.usps'))->toBe(['done', 'manual']);

    S::call($w['admin'], 'DELETE', S::u($w, "usps/{$usp}"))->assertStatus(204);   // the real step falls to not started

    expect(P::st($w, 'ugc.usps'))->toBe(['done', 'manual']);
});

it('F1 row 8: catch-up on open: system rows follow, manual rows at or below the real step are taken back, manual rows beyond stay; a second open writes nothing', function (): void {
    $w = S::world();
    $url = WaveFixtures::wavesUrl($w['agency'], $w['brand'], $w['wave']->ulid.'/tracker');

    // While strategy is OFF: data arrives with no hook, and two rows are set by hand.
    Feature::deactivate(StrategyEnabled::NAME);
    ConceptFixtures::usp($w, 'A');
    $persona = ConceptFixtures::persona($w, 'SK');
    P::hand($w, 'ugc.usps', 'in_progress')->assertOk();   // H = R after catch-up: taken back
    P::hand($w, 'ugc.matrix', 'done')->assertOk();        // H beyond R (matrix not started): left alone
    expect(P::st($w, 'ugc.usps'))->toBe(['in_progress', 'manual'])->and($persona->id)->toBeInt();

    Feature::activate(StrategyEnabled::NAME);
    S::call($w['admin'], 'GET', $url)->assertOk();

    expect(P::st($w, 'ugc.usps'))->toBe(['in_progress', 'system'])
        ->and(P::st($w, 'ugc.personas'))->toBe(['in_progress', 'system'])
        ->and(P::st($w, 'ugc.matrix'))->toBe(['done', 'manual']);

    $audits = AuditLog::query()->where('action', 'wave_step.updated')->count();
    S::call($w['admin'], 'GET', $url)->assertOk();
    expect(AuditLog::query()->where('action', 'wave_step.updated')->count())->toBe($audits);
});

it('F1 row 9: creators and scripts have no real step: any hand-set is saved with no confirm, manual', function (): void {
    $w = S::world();
    foreach (['ugc.creators', 'ugc.scripts'] as $key) {
        P::hand($w, $key, 'done')->assertOk();
        expect(P::st($w, $key))->toBe(['done', 'manual']);
    }
});

it('F1 row 10: with strategy OFF a hand-set beyond is saved with no confirm, manual, and the sync is never called', function (): void {
    $w = S::world();
    Feature::deactivate(StrategyEnabled::NAME);
    $spy = Mockery::mock(WaveStrategyState::class);
    $spy->shouldReceive('active')->andReturn(false);
    $spy->shouldReceive('real')->andReturn([]);
    $spy->shouldNotReceive('catchUp');
    $spy->shouldNotReceive('around');
    app()->instance(WaveStrategyState::class, $spy);

    P::hand($w, 'ugc.usps', 'done')->assertOk();
    S::call($w['admin'], 'GET', WaveFixtures::wavesUrl($w['agency'], $w['brand'], $w['wave']->ulid.'/tracker'))->assertOk();

    expect(P::st($w, 'ugc.usps'))->toBe(['done', 'manual']);
});

it('F1 row 11: a refused (cancelled) hand-set beyond leaves the row, its source and the audit log unchanged', function (): void {
    $w = S::world();
    P::usps($w, 1);
    $before = WaveStep::query()->where('wave_id', $w['wave']->id)->orderBy('id')->get()->map(fn ($s) => $s->only(['status', 'status_source', 'completed_on', 'updated_at']))->all();
    $audits = AuditLog::query()->count();

    P::hand($w, 'ugc.usps', 'done')->assertStatus(409);

    $after = WaveStep::query()->where('wave_id', $w['wave']->id)->orderBy('id')->get()->map(fn ($s) => $s->only(['status', 'status_source', 'completed_on', 'updated_at']))->all();
    expect($after)->toEqual($before)->and(AuditLog::query()->count())->toBe($audits);
});
