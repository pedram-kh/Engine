<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\PostgresCommittedTestCase;
use Tests\Support\ConceptFixtures as C;
use Tests\Support\StrategyFixtures as S;

uses(PostgresCommittedTestCase::class)->group('pg');

/*
 * PL-2.3 — row locks (plan section 1.10). Lock order is the WAVE row, then the CONCEPT row. The 10-image and 300 MB limits
 * are a reservation made at `init` UNDER the concept row lock: two parallel inits queue on it and each sees the other's
 * reservation. SQLite ignores the lock, so these use TWO real PostgreSQL connections (committed data): the instant the
 * app's locking SELECT returns, a second connection's `FOR UPDATE NOWAIT` on the same row must be REFUSED (55P03). Remove
 * `lockForUpdate()` from ConceptService::lockedConcept and the probe gets the row: the spec fails.
 */

beforeEach(fn () => Storage::fake('media'));

function conceptProbe(): Connection
{
    config(['database.connections.pgsql_second' => config('database.connections.'.config('database.default'))]);

    return DB::connection('pgsql_second');
}

function conceptRowLockedByAnother(Connection $probe, string $table, int $id): bool
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

/**
 * Runs $act; returns the ordered list of `table:refused` entries for every locking read of the wave or the concept row.
 *
 * @return list<string>
 */
function conceptLocksWhile(int $waveId, int $conceptId, Closure $act): array
{
    $probe = conceptProbe();
    $seen = [];

    DB::listen(function ($query) use ($probe, $waveId, $conceptId, &$seen): void {
        if ($query->connection === $probe) {
            return;
        }
        $sql = strtolower($query->sql);
        if (! str_contains($sql, 'for update')) {
            return;
        }
        if (str_contains($sql, 'from "waves"')) {
            $seen[] = 'waves:'.(conceptRowLockedByAnother($probe, 'waves', $waveId) ? 'held' : 'FREE');
        } elseif (str_contains($sql, 'from "wave_concepts"')) {
            $seen[] = 'wave_concepts:'.(conceptRowLockedByAnother($probe, 'wave_concepts', $conceptId) ? 'held' : 'FREE');
        }
    });

    $act();

    return $seen;
}

it('CL1: every concept write holds the concept row lock, taken AFTER the wave row lock where the wave is locked', function (): void {
    $w = S::world();
    $p = C::persona($w);
    $ulid = C::make($w, $p);
    $conceptId = (int) DB::table('wave_concepts')->where('ulid', $ulid)->value('id');
    $waveId = $w['wave']->id;
    $png = C::png();

    $steps = [
        'patch' => fn () => S::call($w['staff'], 'PATCH', S::u($w, "concepts/{$ulid}"), ['hook' => 'x'])->assertOk(),
        'decision' => fn () => C::decide($w, $ulid, 'approved')->assertOk(),
        'link add' => fn () => S::call($w['staff'], 'POST', S::u($w, "concepts/{$ulid}/links"), ['url' => 'https://example.com', 'title' => 't'])->assertCreated(),
        'image init' => fn () => S::call($w['staff'], 'POST', S::u($w, "concepts/{$ulid}/images/uploads/init"), ['mime_type' => 'image/png', 'declared_bytes' => strlen($png), 'original_filename' => 'a.png'])->assertCreated(),
        'image upload' => fn () => C::upload($w, $ulid, $png)->assertCreated(),
        'duplicate' => fn () => S::call($w['staff'], 'POST', S::u($w, "concepts/{$ulid}/duplicate"))->assertCreated(),
    ];

    foreach ($steps as $name => $act) {
        $seen = conceptLocksWhile($waveId, $conceptId, $act);
        expect([$name => in_array('wave_concepts:held', $seen, true)])->toBe([$name => true]);
        expect($seen)->not->toContain('wave_concepts:FREE')->and($seen)->not->toContain('waves:FREE');
        $firstConcept = array_search('wave_concepts:held', $seen, true);
        $firstWave = array_search('waves:held', $seen, true);
        if ($firstWave !== false && $name !== 'image init' && $name !== 'image upload') {
            expect([$name => $firstWave < $firstConcept])->toBe([$name => true]);
        }
    }

    // Delete locks the wave, then the concept, in the final transaction.
    $seen = conceptLocksWhile($waveId, $conceptId, fn () => S::call($w['admin'], 'DELETE', S::u($w, "concepts/{$ulid}"))->assertNoContent());
    expect($seen)->toContain('waves:held')->and($seen)->toContain('wave_concepts:held')->and($seen)->not->toContain('wave_concepts:FREE')->and($seen)->not->toContain('waves:FREE');
    expect(array_search('waves:held', $seen, true) < array_search('wave_concepts:held', $seen, true))->toBeTrue();
});

it('CL2: the lock is released when the request ends', function (): void {
    $w = S::world();
    $ulid = C::make($w, C::persona($w));
    $conceptId = (int) DB::table('wave_concepts')->where('ulid', $ulid)->value('id');
    S::call($w['staff'], 'PATCH', S::u($w, "concepts/{$ulid}"), ['hook' => 'x'])->assertOk();

    expect(conceptRowLockedByAnother(conceptProbe(), 'wave_concepts', $conceptId))->toBeFalse()
        ->and(conceptRowLockedByAnother(conceptProbe(), 'waves', $w['wave']->id))->toBeFalse();
});

it('CL3: image init WAITS for a connection that holds the concept row, so parallel reservations cannot both pass the limits', function (): void {
    $w = S::world();
    $ulid = C::make($w, C::persona($w));
    $conceptId = (int) DB::table('wave_concepts')->where('ulid', $ulid)->value('id');
    $this->withoutExceptionHandling();

    // A "parallel upload" is mid-reservation: it holds the concept row. A second init must queue behind it; with a short
    // lock timeout it fails instead of reserving blind.
    $holder = conceptProbe();
    $holder->beginTransaction();
    $holder->select('select id from wave_concepts where id = ? for update', [$conceptId]);
    DB::statement("set lock_timeout = '300ms'");

    try {
        expect(fn () => S::call($w['staff'], 'POST', S::u($w, "concepts/{$ulid}/images/uploads/init"), ['mime_type' => 'image/png', 'declared_bytes' => 1000, 'original_filename' => 'a.png']))
            ->toThrow(QueryException::class, 'lock timeout');
    } finally {
        $holder->rollBack();
        DB::statement("set lock_timeout = '0'");
    }
    expect(DB::table('wave_concept_images')->count())->toBe(0);

    // Released: the same init goes through.
    S::call($w['staff'], 'POST', S::u($w, "concepts/{$ulid}/images/uploads/init"), ['mime_type' => 'image/png', 'declared_bytes' => 1000, 'original_filename' => 'a.png'])->assertCreated();
});

it('CL4: two reservations in a row see each other: the second is refused at the 300 MB line', function (): void {
    $w = S::world();
    $ulid = C::make($w, C::persona($w));
    $init = fn (int $bytes) => S::call($w['staff'], 'POST', S::u($w, "concepts/{$ulid}/images/uploads/init"), ['mime_type' => 'image/png', 'declared_bytes' => $bytes, 'original_filename' => 'a.png']);

    $init(200 * 1024 * 1024)->assertCreated();
    $init(101 * 1024 * 1024)->assertUnprocessable()->assertJsonPath('errors.0.code', 'concept_image.limit_bytes');
    $init(100 * 1024 * 1024)->assertCreated();
    expect(DB::table('wave_concept_images')->count())->toBe(2);
});

it('CL5: a persona delete and a USP delete lock the wave row and see a concept created a moment earlier', function (): void {
    $w = S::world();
    $p = C::persona($w);
    C::make($w, $p);
    $waveId = $w['wave']->id;

    $seen = conceptLocksWhile($waveId, 0, fn () => S::call($w['admin'], 'DELETE', S::u($w, "personas/{$p->ulid}"))->assertUnprocessable());
    expect($seen)->toContain('waves:held')->and($seen)->not->toContain('waves:FREE');
    expect(DB::table('wave_personas')->where('id', $p->id)->exists())->toBeTrue();
});
