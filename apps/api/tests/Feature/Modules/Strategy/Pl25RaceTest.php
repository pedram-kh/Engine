<?php

declare(strict_types=1);

use App\Core\Tenancy\TenancyContext;
use App\Modules\Strategy\Features\StrategyEnabled;
use App\Modules\Strategy\Services\ConceptDecisionService;
use App\Modules\Waves\Models\WaveStep;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use Tests\Support\Pl25Fixtures as P;
use Tests\Support\StrategyFixtures as S;
use Tests\TestCase;

uses(TestCase::class);

/*
 * PL-2.5 B19 / A2: a true two-connection race on PostgreSQL. A concept decision (the last concept approved, so Concepts
 * becomes done) and a hand-set of the same tracker row to Done (no confirm) run in two PHP processes at the same moment,
 * released together by an advisory lock. The end state must be one of two consistent outcomes, never a mix:
 *   (1) the decision first, then the hand-set sees real = done: accepted, row done, source system (F1 row 3);
 *   (2) the hand-set first, refused 409 step.incomplete, then the decision sets the row done as system.
 * It needs its own throwaway database (committed data across processes); SQLite skips it.
 */
it('B19 / A2: a concept decision and a hand-set of the same row, on two connections, end in one of the two consistent states', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL only: SQLite has one writer and no row locks.');
    }

    $original = (string) Config::get('database.connections.pgsql.database');
    $race = 'pl25_race_'.getmypid().'_test';
    expect(str_ends_with($race, '_test'))->toBeTrue()->and($race)->not->toBe('catalyst');
    DB::statement('CREATE DATABASE "'.$race.'"');

    $children = [];
    try {
        Config::set('database.connections.pgsql.database', $race);
        DB::purge('pgsql');
        expect(DB::connection('pgsql')->getDatabaseName())->toBe($race);
        Artisan::call('migrate:fresh', ['--force' => true]);

        $w = S::world();
        S::keepMarkets($w, ['SK'])->assertOk();
        [$persona] = P::personas($w, 'SK');
        $concept = P::concept($w, 'SK', $persona);
        $step = P::row($w, 'ugc.concepts');
        // Both flags are in-memory in the test; the children arm their own.
        expect(Feature::active(StrategyEnabled::NAME))->toBeTrue();

        $barrier = 770025;
        DB::select('select pg_advisory_lock(?)', [$barrier]);

        $base = ['agency_id' => $w['agency']->id, 'brand_id' => $w['brand']->id, 'wave_id' => $w['wave']->id, 'barrier' => $barrier];
        $jobs = [
            'decide' => $base + ['action' => 'decide', 'user_id' => $w['manager']->id, 'concept' => $concept],
            'hand' => $base + ['action' => 'hand', 'user_id' => $w['admin']->id, 'step' => $step->ulid],
        ];
        $env = array_merge(getenv(), ['DB_DATABASE' => $race, 'PENNANT_STORE' => 'array', 'APP_ENV' => 'testing']);
        foreach ($jobs as $name => $job) {
            $pipes = [];
            $proc = proc_open([PHP_BINARY, base_path('tests/Support/pl25_race_child.php'), base64_encode((string) json_encode($job))], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
            $children[$name] = [$proc, $pipes];
        }

        // Wait until both children are parked on the barrier, then release them together.
        $deadline = microtime(true) + 60;
        do {
            usleep(100_000);
            $waiting = (int) DB::selectOne("select count(*) as n from pg_locks where locktype = 'advisory' and not granted")->n;
        } while ($waiting < 2 && microtime(true) < $deadline);
        expect($waiting)->toBe(2);
        DB::select('select pg_advisory_unlock(?)', [$barrier]);

        $out = [];
        foreach ($children as $name => [$proc, $pipes]) {
            $out[$name] = json_decode((string) stream_get_contents($pipes[1]), true) ?? ['error' => stream_get_contents($pipes[2])];
            if (is_resource($proc)) {
                proc_close($proc);
            }
        }
        $children = [];

        $row = WaveStep::query()->whereKey($step->id)->firstOrFail();
        expect($out['decide']['result'] ?? null)->toBe('ok');
        $hand = $out['hand']['result'] ?? null;
        expect($hand)->toBeIn(['ok', 'step.incomplete']);

        // Outcome 1: decision first, hand-set accepted as equal to the real state: system. Outcome 2: hand-set refused,
        // then the decision set the row as system. Both end done and system; never done/manual, never in_progress.
        expect([$row->status->value, $row->status_source->value])->toBe(['done', 'system']);
    } finally {
        foreach ($children as [$child]) {
            if (is_resource($child)) {
                proc_terminate($child);
            }
        }
        Config::set('database.connections.pgsql.database', $original);
        DB::purge('pgsql');
        DB::connection('pgsql')->statement('DROP DATABASE IF EXISTS "'.$race.'" WITH (FORCE)');
    }
});

/*
 * The same two outcomes, forced instead of hoped for: the decision is written on this connection and left UNCOMMITTED
 * (it holds the wave lock), then the hand-set starts on another connection. It must wait for the lock (still running a
 * moment later), and once the decision commits it sees real = done and is accepted as system. Without the wave lock the
 * hand-set would read the old state, answer 409 at once and this test fails.
 */
it('B19 / A2: a hand-set waits for an uncommitted decision on the same wave, then sees the committed real state', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL only: SQLite has one writer and no row locks.');
    }

    $original = (string) Config::get('database.connections.pgsql.database');
    $race = 'pl25_race_'.getmypid().'_test';
    expect(str_ends_with($race, '_test'))->toBeTrue()->and($race)->not->toBe('catalyst');
    DB::statement('CREATE DATABASE "'.$race.'"');

    $proc = null;
    $open = false;
    try {
        Config::set('database.connections.pgsql.database', $race);
        DB::purge('pgsql');
        expect(DB::connection('pgsql')->getDatabaseName())->toBe($race);
        Artisan::call('migrate:fresh', ['--force' => true]);

        $w = S::world();
        S::keepMarkets($w, ['SK'])->assertOk();
        [$persona] = P::personas($w, 'SK');
        $concept = P::concept($w, 'SK', $persona);
        $step = P::row($w, 'ugc.concepts');
        app(TenancyContext::class)->setAgencyId($w['agency']->id);

        DB::beginTransaction();
        $open = true;
        app(ConceptDecisionService::class)->record($w['brand'], $w['wave'], $concept, $w['manager'], ['status' => 'approved', 'client_name' => 'Anna K.']);

        $job = ['agency_id' => $w['agency']->id, 'brand_id' => $w['brand']->id, 'wave_id' => $w['wave']->id, 'barrier' => 0, 'action' => 'hand', 'user_id' => $w['admin']->id, 'step' => $step->ulid];
        $env = array_merge(getenv(), ['DB_DATABASE' => $race, 'PENNANT_STORE' => 'array', 'APP_ENV' => 'testing']);
        $pipes = [];
        $proc = proc_open([PHP_BINARY, base_path('tests/Support/pl25_race_child.php'), base64_encode((string) json_encode($job))], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);

        // Give the child time to boot and reach the lock: it must still be running (waiting) while we hold it.
        sleep(4);
        expect(is_resource($proc) && proc_get_status($proc)['running'])->toBeTrue('the hand-set must wait for the wave lock held by the uncommitted decision');

        DB::commit();
        $open = false;

        $out = json_decode((string) stream_get_contents($pipes[1]), true) ?? ['error' => stream_get_contents($pipes[2])];
        if (is_resource($proc)) {
            proc_close($proc);
        }
        $proc = null;

        $row = WaveStep::query()->whereKey($step->id)->firstOrFail();
        expect($out['result'] ?? null)->toBe('ok')->and([$row->status->value, $row->status_source->value])->toBe(['done', 'system']);
    } finally {
        if ($open) {
            DB::rollBack();
        }
        if (is_resource($proc)) {
            proc_terminate($proc);
        }
        Config::set('database.connections.pgsql.database', $original);
        DB::purge('pgsql');
        DB::connection('pgsql')->statement('DROP DATABASE IF EXISTS "'.$race.'" WITH (FORCE)');
    }
});
