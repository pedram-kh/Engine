<?php

declare(strict_types=1);

use App\Modules\Strategy\Models\WaveCreator;
use App\Modules\Strategy\Services\WaveCreatorGuard;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresCommittedTestCase;
use Tests\Support\CreatorFixtures as F;
use Tests\Support\StrategyFixtures as S;

uses(PostgresCommittedTestCase::class)->group('pg');

/*
 * PL-3.1 B20, B38 and the Notes CHECK: PostgreSQL only (row locks, a second connection, a CHECK constraint). Run by file.
 */

function creatorsProbe(): Connection
{
    config(['database.connections.pgsql_second' => config('database.connections.'.config('database.default'))]);

    return DB::connection('pgsql_second');
}

function creatorsWaveLockedByAnother(Connection $probe, int $waveId): bool
{
    $probe->beginTransaction();
    try {
        $probe->select('select id from waves where id = ? for update nowait', [$waveId]);

        return false;
    } catch (QueryException $e) {
        return $e->getCode() === '55P03';
    } finally {
        $probe->rollBack();
    }
}

it('B38: every creator write takes the WAVE lock as the first statement of its transaction, and holds it against a second connection', function (): void {
    $w = F::world();
    $a = F::applicant($w['campaign']);
    $b = F::applicant($w['campaign']);
    $slot = F::slot($w, $b, 'RO');
    $probe = creatorsProbe();

    $calls = [
        'add' => fn () => S::call($w['admin'], 'POST', F::url($w), ['market' => $w['mk']['SK'], 'creators' => [$a->ulid]])->assertCreated(),
        'edit' => fn () => S::call($w['admin'], 'PATCH', F::url($w, $slot->ulid), ['slot_code' => 'ZED'])->assertOk(),
        'decide' => fn () => S::call($w['admin'], 'POST', F::url($w, "{$slot->ulid}/decisions"), ['status' => 'approved', 'client_name' => 'Anna'])->assertCreated(),
        'replace' => fn () => S::call($w['admin'], 'POST', F::url($w, "{$slot->ulid}/replace"), ['creator' => F::applicant($w['campaign'])->ulid])->assertOk(),
        'remove' => fn () => S::call($w['admin'], 'DELETE', F::url($w, $slot->ulid))->assertOk(),
    ];

    foreach ($calls as $name => $act) {
        $first = null;
        $refused = [];
        DB::listen(function ($query) use ($probe, $w, &$first, &$refused): void {
            if ($query->connection === $probe) {
                return;
            }
            $sql = strtolower($query->sql);
            if ($first === null && DB::transactionLevel() >= 1) {
                $first = $sql;
            }
            if (str_contains($sql, 'from "waves"') && str_contains($sql, 'for update')) {
                $refused[] = creatorsWaveLockedByAnother($probe, $w['wave']->id);
            }
        });
        $act();

        expect($first)->not->toBeNull();
        expect(str_contains((string) $first, 'from "waves"') && str_contains((string) $first, 'for update'))->toBeTrue("{$name}: first statement in the transaction is the wave lock");
        expect($refused)->not->toBeEmpty();
        expect(in_array(false, $refused, true))->toBeFalse("{$name}: a second connection was not refused while the lock was held");
    }
});

it('B20: the same creator added to two markets on two connections at the same moment: one wins, one is refused, one row', function (): void {
    $w = F::world();
    $creator = F::applicant($w['campaign']);
    $config = config('database.connections.'.config('database.default'));
    $database = (string) $config['database'];
    expect($database)->toEndWith('_test')->and($database)->not->toBe('catalyst');

    $barrier = 770031;
    DB::select('select pg_advisory_lock(?)', [$barrier]);
    $env = array_merge(getenv(), ['DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => $database, 'DB_HOST' => (string) $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_USERNAME' => (string) $config['username'], 'DB_PASSWORD' => (string) $config['password'], 'PENNANT_STORE' => 'array', 'APP_ENV' => 'testing']);
    $base = ['agency_id' => $w['agency']->id, 'brand_id' => $w['brand']->id, 'wave_id' => $w['wave']->id, 'user_id' => $w['admin']->id, 'barrier' => $barrier, 'creator' => $creator->ulid];

    $children = [];
    try {
        foreach (['SK', 'RO'] as $code) {
            $pipes = [];
            $proc = proc_open([PHP_BINARY, base_path('tests/Support/creators_race_child.php'), base64_encode((string) json_encode($base + ['market' => $w['mk'][$code]]))], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
            $children[$code] = [$proc, $pipes];
        }

        $deadline = microtime(true) + 60;
        do {
            usleep(100_000);
            $waiting = (int) DB::selectOne("select count(*) as n from pg_locks where locktype = 'advisory' and not granted")->n;
        } while ($waiting < 2 && microtime(true) < $deadline);
        expect($waiting)->toBe(2);
        DB::select('select pg_advisory_unlock(?)', [$barrier]);

        $results = [];
        foreach ($children as $code => [$proc, $pipes]) {
            $out = json_decode((string) stream_get_contents($pipes[1]), true) ?? ['error' => stream_get_contents($pipes[2])];
            $results[] = $out['result'] ?? json_encode($out);
            if (is_resource($proc)) {
                proc_close($proc);
            }
        }
        $children = [];
    } finally {
        foreach ($children as [$proc]) {
            if (is_resource($proc)) {
                proc_terminate($proc);
            }
        }
    }

    sort($results);
    expect($results)->toBe(['ok', 'wave_creator.already_in_wave'])
        ->and(WaveCreator::query()->where('wave_id', $w['wave']->id)->count())->toBe(1);
});

it('N1: the Notes step CHECK accepts creators and still refuses any other step', function (): void {
    $w = F::world();
    $row = fn (string $step) => DB::table('wave_strategy_notes')->insert([
        'ulid' => (string) Str::ulid(), 'agency_id' => $w['agency']->id, 'wave_id' => $w['wave']->id,
        'market_id' => $w['mkid']['SK'], 'step' => $step, 'body' => 'x', 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect($row('creators'))->toBeTrue();
    expect(fn () => $row('bogus'))->toThrow(QueryException::class);
});

it('B20: an add waits for the wave lock held by another uncommitted transaction, then runs (forced, not hoped for)', function (): void {
    $w = F::world();
    $creator = F::applicant($w['campaign']);
    $config = config('database.connections.'.config('database.default'));
    $database = (string) $config['database'];
    expect($database)->toEndWith('_test')->and($database)->not->toBe('catalyst');
    $env = array_merge(getenv(), ['DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => $database, 'DB_HOST' => (string) $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_USERNAME' => (string) $config['username'], 'DB_PASSWORD' => (string) $config['password'], 'PENNANT_STORE' => 'array', 'APP_ENV' => 'testing']);
    $job = ['agency_id' => $w['agency']->id, 'brand_id' => $w['brand']->id, 'wave_id' => $w['wave']->id, 'user_id' => $w['admin']->id, 'barrier' => 0, 'creator' => $creator->ulid, 'market' => $w['mk']['SK']];

    $proc = null;
    $open = false;
    try {
        DB::beginTransaction();
        $open = true;
        WaveCreatorGuard::lockWave($w['wave']);

        $pipes = [];
        $proc = proc_open([PHP_BINARY, base_path('tests/Support/creators_race_child.php'), base64_encode((string) json_encode($job))], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
        sleep(4);
        expect(is_resource($proc) && proc_get_status($proc)['running'])->toBeTrue('the add must wait for the wave lock');
        expect(WaveCreator::query()->where('wave_id', $w['wave']->id)->count())->toBe(0);

        DB::commit();
        $open = false;
        $out = json_decode((string) stream_get_contents($pipes[1]), true) ?? ['error' => stream_get_contents($pipes[2])];
        if (is_resource($proc)) {
            proc_close($proc);
        }
        $proc = null;

        expect($out['result'] ?? null)->toBe('ok')->and(WaveCreator::query()->where('wave_id', $w['wave']->id)->count())->toBe(1);
    } finally {
        if ($open) {
            DB::rollBack();
        }
        if (is_resource($proc)) {
            proc_terminate($proc);
        }
    }
});
