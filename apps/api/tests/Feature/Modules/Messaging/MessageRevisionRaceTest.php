<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\PostgresCommittedTestCase;

uses(PostgresCommittedTestCase::class)->group('pg');

require_once dirname(__DIR__, 3).'/Support/MessageRevisionFixtures.php';

/*
 * AH-119 B2 — the message row lock, proved with TWO real connections (committed data). SQLite ignores
 * `lockForUpdate()`; on Postgres it is the only thing that stops an edit and a delete (or two edits)
 * writing past each other.
 *
 *   PROBE   while the app holds its lock (seen the instant its locking SELECT returns), a second
 *           connection's `FOR UPDATE NOWAIT` on the same message row is REFUSED (SQLSTATE 55P03).
 *           Remove `lockForUpdate()` from MessageRevisionService::lock() and the probe gets the row.
 *   HOLDER  with a second connection holding the row, the app's write waits and, at a 300 ms
 *           lock_timeout, fails with 55P03 instead of writing around the holder.
 */

function revSecondConnection(): Connection
{
    config(['database.connections.pgsql_second' => config('database.connections.'.config('database.default'))]);

    return DB::connection('pgsql_second');
}

function revRowIsLockedByAnother(Connection $probe, string $table, int $id): bool
{
    $probe->beginTransaction();
    try {
        $probe->select("select id from {$table} where id = ? for update nowait", [$id]);

        return false;
    } catch (QueryException $e) {
        return $e->getCode() === '55P03';
    } finally {
        $probe->rollBack();
    }
}

/** @return list<bool> per locking read on the table the app issued: was a second connection refused? */
function revProbeWhileAppLocks(string $table, int $id, Closure $act): array
{
    $probe = revSecondConnection();
    $seen = [];

    DB::listen(function ($query) use ($probe, $table, $id, &$seen): void {
        if ($query->connection === $probe) {
            return;
        }
        if (str_contains($query->sql, 'from "'.$table.'"') && str_contains(strtolower($query->sql), 'for update')) {
            $seen[] = revRowIsLockedByAnother($probe, $table, $id);
        }
    });

    $act();

    return $seen;
}

it('PROBE: an edit holds the message row lock while it checks and writes', function (string $surface): void {
    $s = revSurface(revWorld(), $surface, 'before');

    $seen = revProbeWhileAppLocks($s['table'], $s['message']->id, fn () => $this->actingAs($s['actor'])->patchJson($s['url'], ['body' => 'after'])->assertOk());

    expect($seen)->toBe([true], 'the second connection was NOT refused: the edit does not hold the row')
        ->and(revRow($s['table'], $s['message']->ulid)->body)->toBe('after');
})->with(revSurfaceDataset());

it('PROBE: a delete holds the message row lock while it checks and writes', function (string $surface): void {
    $s = revSurface(revWorld(), $surface, 'before');

    $seen = revProbeWhileAppLocks($s['table'], $s['message']->id, fn () => $this->actingAs($s['actor'])->deleteJson($s['url'])->assertOk());

    expect($seen)->toBe([true], 'the second connection was NOT refused: the delete does not hold the row')
        ->and(revRow($s['table'], $s['message']->ulid)->deleted_at)->not->toBeNull();
})->with(revSurfaceDataset());

it('HOLDER: an edit waits behind a lock another connection holds, and does not write around it', function (string $surface): void {
    $s = revSurface(revWorld(), $surface, 'before');
    $holder = revSecondConnection();

    $holder->beginTransaction();
    try {
        $holder->select("select id from {$s['table']} where id = ? for update", [$s['message']->id]);
        DB::statement("set lock_timeout = '300ms'");
        $this->withoutExceptionHandling();

        $started = microtime(true);
        $threw = null;
        try {
            $this->actingAs($s['actor'])->patchJson($s['url'], ['body' => 'after']);
        } catch (QueryException $e) {
            $threw = $e;
        }
        $waited = microtime(true) - $started;
    } finally {
        $holder->rollBack();
        DB::statement('set lock_timeout = 0');
    }

    $row = revRow($s['table'], $s['message']->ulid);
    expect($threw)->toBeInstanceOf(QueryException::class)
        ->and($threw?->getCode())->toBe('55P03')
        ->and($waited)->toBeGreaterThan(0.25)
        ->and($row->body)->toBe('before')->and($row->edited_at)->toBeNull();
})->with(revSurfaceDataset());

it('HOLDER: a delete waits behind a lock another connection holds, and deletes nothing', function (string $surface): void {
    $s = revSurface(revWorld(), $surface, 'before');
    $holder = revSecondConnection();

    $holder->beginTransaction();
    try {
        $holder->select("select id from {$s['table']} where id = ? for update", [$s['message']->id]);
        DB::statement("set lock_timeout = '300ms'");
        $this->withoutExceptionHandling();

        $threw = null;
        try {
            $this->actingAs($s['actor'])->deleteJson($s['url']);
        } catch (QueryException $e) {
            $threw = $e;
        }
    } finally {
        $holder->rollBack();
        DB::statement('set lock_timeout = 0');
    }

    $row = revRow($s['table'], $s['message']->ulid);
    expect($threw)->toBeInstanceOf(QueryException::class)
        ->and($threw?->getCode())->toBe('55P03')
        ->and($row->deleted_at)->toBeNull()->and($row->body)->toBe('before');
})->with(revSurfaceDataset());

it('serialises an edit behind a committed delete: the loser sees already_deleted, never a resurrected body', function (string $surface): void {
    $s = revSurface(revWorld(), $surface, 'before');
    $other = revSecondConnection();

    // The "winner" deletes and commits on its own connection; the edit then runs and must refuse.
    $other->table($s['table'])->where('id', $s['message']->id)->update(['deleted_body' => 'before', 'body' => null, 'deleted_at' => now()]);

    $this->actingAs($s['actor'])->patchJson($s['url'], ['body' => 'zombie'])->assertUnprocessable()->assertJsonPath('errors.0.code', 'message.already_deleted');

    $row = revRow($s['table'], $s['message']->ulid);
    expect($row->body)->toBeNull()->and($row->deleted_body)->toBe('before');
})->with(revSurfaceDataset());
