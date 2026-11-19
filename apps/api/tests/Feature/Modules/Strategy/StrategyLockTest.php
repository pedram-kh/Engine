<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\PostgresCommittedTestCase;
use Tests\Support\StrategyFixtures as S;

uses(PostgresCommittedTestCase::class)->group('pg');

/*
 * PL-2.1 L5 — every strategy write serialises on the WAVE ROW (the cap of 6 USPs / 8 personas, the dense positions
 * and the slot choice are all decided under it). SQLite ignores the lock, so these use TWO real PostgreSQL connections
 * (committed data): the instant the app's locking SELECT on `waves` returns, a second connection's
 * `FOR UPDATE NOWAIT` on the same row must be REFUSED (SQLSTATE 55P03). Remove `lockForUpdate()` and the probe gets
 * the row: the spec fails. Precedent: Campaigns/PostgresRowLockTest.
 */

function strategyProbeConnection(): Connection
{
    config(['database.connections.pgsql_second' => config('database.connections.'.config('database.default'))]);

    return DB::connection('pgsql_second');
}

function strategyWaveLockedByAnother(Connection $probe, int $waveId): bool
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

/**
 * Runs $act; returns, per locking read of the wave row the app issued, whether the second connection was refused then.
 *
 * @return list<bool>
 */
function strategyProbeWhileAppLocks(int $waveId, Closure $act): array
{
    $probe = strategyProbeConnection();
    $seen = [];

    DB::listen(function ($query) use ($probe, $waveId, &$seen): void {
        if ($query->connection === $probe) {
            return;
        }
        $sql = strtolower($query->sql);
        if (str_contains($sql, 'from "waves"') && str_contains($sql, 'for update')) {
            $seen[] = strategyWaveLockedByAnother($probe, $waveId);
        }
    });

    $act();

    return $seen;
}

it('L5: each USP write holds the wave row lock against a second connection', function (): void {
    $w = S::world();
    $id = $w['wave']->id;
    $ulid = null;

    $seen = strategyProbeWhileAppLocks($id, function () use ($w, &$ulid): void {
        $ulid = S::call($w['admin'], 'POST', S::u($w, 'usps'), ['title' => 'One'])->assertCreated()->json('data.id');
    });
    expect($seen)->not->toBeEmpty()->and($seen)->each->toBeTrue();

    foreach ([
        ['PATCH', "usps/{$ulid}", ['title' => 'Two']],
        ['PUT', 'usps/order', ['order' => [$ulid]]],
        ['PATCH', 'audience-title', ['usp_audience_title' => 'Eaters']],
        ['DELETE', "usps/{$ulid}", []],
    ] as [$method, $suffix, $body]) {
        $seen = strategyProbeWhileAppLocks($id, fn () => S::call($w['admin'], $method, S::u($w, $suffix), $body)->assertSuccessful());
        expect($seen)->not->toBeEmpty("{$method} {$suffix} took no wave lock")->and($seen)->each->toBeTrue();
    }
});

it('L5: the lock is released when the request ends (a second connection gets the row)', function (): void {
    $w = S::world();
    S::call($w['admin'], 'POST', S::u($w, 'usps'), ['title' => 'One'])->assertCreated();

    expect(strategyWaveLockedByAnother(strategyProbeConnection(), $w['wave']->id))->toBeFalse();
});

it('L5: each persona and angle write (create, update, copy, angle add, edit, remove, delete) holds the wave row lock', function (): void {
    $w = S::world();
    $id = $w['wave']->id;
    $usp = S::call($w['admin'], 'POST', S::u($w, 'usps'), ['title' => 'U'])->assertCreated()->json('data.id');
    $persona = null;
    $angle = null;

    $steps = [
        ['POST', 'personas', fn () => ['market' => 'SK', 'title' => 'T', 'name' => 'N'], function ($r) use (&$persona): void {
            $persona = $r->json('data.id');
        }],
        ['PATCH', function () use (&$persona, &$angle) {
            return "personas/{$persona}";
        }, fn () => ['title' => 'T2'], null],
        ['POST', 'personas/copy', fn () => ['from_market' => 'SK', 'to_market' => 'RO'], null],
        ['POST', function () use (&$persona, &$angle) {
            return "personas/{$persona}/angles";
        }, fn () => ['usp' => $usp, 'explanation' => 'x'], function ($r) use (&$angle): void {
            $angle = $r->json('data.id');
        }],
        ['PATCH', function () use (&$persona, &$angle) {
            return "personas/{$persona}/angles/{$angle}";
        }, fn () => ['explanation' => 'y'], null],
        ['DELETE', function () use (&$persona, &$angle) {
            return "personas/{$persona}/angles/{$angle}";
        }, fn () => [], null],
        ['DELETE', function () use (&$persona, &$angle) {
            return "personas/{$persona}";
        }, fn () => [], null],
    ];

    foreach ($steps as [$method, $suffix, $body, $after]) {
        $path = is_callable($suffix) ? $suffix() : $suffix;
        $seen = strategyProbeWhileAppLocks($id, function () use ($w, $method, $path, $body, $after): void {
            $res = S::call($w['admin'], $method, S::u($w, $path), $body())->assertSuccessful();
            if ($after) {
                $after($res);
            }
        });
        expect($seen)->not->toBeEmpty("{$method} {$path} took no wave lock")->and($seen)->each->toBeTrue();
    }
});
