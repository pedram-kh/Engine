<?php

declare(strict_types=1);

use App\Modules\Creators\Models\Creator;
use App\Modules\Strategy\Models\WaveScript;
use App\Modules\Strategy\Models\WaveScriptHook;
use App\Modules\Strategy\Models\WaveScriptHookText;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresCommittedTestCase;
use Tests\Support\CreatorFixtures as F;
use Tests\Support\ScriptFixtures as SF;
use Tests\Support\StrategyFixtures as S;

uses(PostgresCommittedTestCase::class)->group('pg');

/*
 * PL-3.2a G4, G5, G13, G14: PostgreSQL only (row locks, a second connection, constraints). Run by file.
 */

function scriptsProbe(): Connection
{
    config(['database.connections.pgsql_second' => config('database.connections.'.config('database.default'))]);

    return DB::connection('pgsql_second');
}

function scriptsWaveLockedByAnother(Connection $probe, int $waveId): bool
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
 * Run two children at the same moment (a barrier) and return their results, sorted.
 *
 * @param  array<string, mixed>  $base
 * @param  list<array<string, mixed>>  $sides
 * @return list<string>
 */
function scriptsRace(array $w, array $base, array $sides): array
{
    $config = config('database.connections.'.config('database.default'));
    $database = (string) $config['database'];
    expect($database)->toEndWith('_test')->and($database)->not->toBe('catalyst');

    $barrier = 770032;
    DB::select('select pg_advisory_lock(?)', [$barrier]);
    $env = array_merge(getenv(), ['DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => $database, 'DB_HOST' => (string) $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_USERNAME' => (string) $config['username'], 'DB_PASSWORD' => (string) $config['password'], 'PENNANT_STORE' => 'array', 'APP_ENV' => 'testing']);
    $common = ['agency_id' => $w['agency']->id, 'brand_id' => $w['brand']->id, 'wave_id' => $w['wave']->id, 'user_id' => $w['admin']->id, 'barrier' => $barrier];

    $children = [];
    $results = [];
    try {
        foreach ($sides as $i => $side) {
            $pipes = [];
            $proc = proc_open([PHP_BINARY, base_path('tests/Support/scripts_race_child.php'), base64_encode((string) json_encode($common + $base + $side + ['side' => $i]))], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
            $children[$i] = [$proc, $pipes];
        }

        $deadline = microtime(true) + 60;
        do {
            usleep(100_000);
            $waiting = (int) DB::selectOne("select count(*) as n from pg_locks where locktype = 'advisory' and not granted")->n;
        } while ($waiting < count($sides) && microtime(true) < $deadline);
        expect($waiting)->toBe(count($sides));
        DB::select('select pg_advisory_unlock(?)', [$barrier]);

        foreach ($children as [$proc, $pipes]) {
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

    return $results;
}

it('G14: every script write takes the WAVE lock first, then the SCRIPT row lock, and holds the wave lock against a second connection', function (): void {
    $w = SF::world();
    [$slot] = SF::slot($w);
    $script = SF::script($slot);
    $hook = SF::hooksOf($script)[0];
    $extra = SF::script(SF::slot($w, 'RO')[0]);
    $probe = scriptsProbe();

    $calls = [
        'create' => [false, fn () => S::call($w['admin'], 'POST', SF::url($w), ['slot' => $slot->ulid])->assertCreated()],
        'brief' => [true, fn () => S::call($w['admin'], 'PUT', SF::url($w, "{$script->ulid}/brief"), ['duration_seconds' => 45, 'revision' => 1])->assertOk()],
        'hook edit' => [true, fn () => S::call($w['admin'], 'PUT', SF::url($w, "{$script->ulid}/hooks/{$hook->ulid}"), ['shot_type' => 'wide', 'revision' => 1])->assertOk()],
        'hook add' => [true, fn () => S::call($w['admin'], 'POST', SF::url($w, "{$script->ulid}/hooks"))->assertCreated()],
        'hook duplicate' => [true, fn () => S::call($w['admin'], 'POST', SF::url($w, "{$script->ulid}/hooks/{$hook->ulid}/duplicate"))->assertCreated()],
        'hook delete' => [true, fn () => S::call($w['admin'], 'DELETE', SF::url($w, "{$script->ulid}/hooks/{$hook->ulid}"))->assertOk()],
        'script archive' => [true, fn () => S::call($w['admin'], 'POST', SF::url($w, "{$extra->ulid}/archive"))->assertOk()],
        'script restore' => [true, fn () => S::call($w['admin'], 'POST', SF::url($w, "archived/{$extra->ulid}/restore"))->assertOk()],
        'script delete (from the archive)' => [true, function () use ($w, $extra): void {
            DB::table('wave_scripts')->where('id', $extra->id)->update(['wave_creator_id' => null, 'archived_at' => now()]);
            S::call($w['admin'], 'DELETE', SF::url($w, "archived/{$extra->ulid}"))->assertOk();
        }],
    ];

    foreach ($calls as $name => [$locksScript, $act]) {
        $statements = [];
        $refused = [];
        DB::listen(function ($query) use ($probe, $w, &$statements, &$refused): void {
            if ($query->connection === $probe) {
                return;
            }
            $sql = strtolower($query->sql);
            if (DB::transactionLevel() >= 1) {
                $statements[] = $sql;
            }
            if (str_contains($sql, 'from "waves"') && str_contains($sql, 'for update')) {
                $refused[] = scriptsWaveLockedByAnother($probe, $w['wave']->id);
            }
        });
        $act();

        $waveAt = null;
        $scriptAt = null;
        foreach ($statements as $i => $sql) {
            if ($waveAt === null && str_contains($sql, 'from "waves"') && str_contains($sql, 'for update')) {
                $waveAt = $i;
            }
            if ($scriptAt === null && str_contains($sql, 'from "wave_scripts"') && str_contains($sql, 'for update')) {
                $scriptAt = $i;
            }
        }
        expect($waveAt)->toBe(0, "{$name}: the wave lock is the first statement of the transaction");
        if ($locksScript) {
            expect($scriptAt)->not->toBeNull("{$name}: the script row is locked")->and($scriptAt > 0)->toBeTrue("{$name}: script lock after wave lock");
        }
        expect($refused)->not->toBeEmpty();
        expect(in_array(false, $refused, true))->toBeFalse("{$name}: a second connection was not refused while the lock was held");
    }
});

it('G4: two creates at the same moment for a creator with two scripts leave three, not four', function (): void {
    $w = SF::world();
    [$slot] = SF::slot($w);
    SF::script($slot);
    SF::script($slot);

    $results = scriptsRace($w, ['mode' => 'create', 'slot' => $slot->ulid], [[], []]);

    expect($results)->toBe(['ok', 'wave_script.limit_reached'])
        ->and(WaveScript::query()->where('creator_id', $slot->creator_id)->orderBy('number')->pluck('number')->all())->toBe([1, 2, 3]);
});

it('G4b: the database refuses a fourth number, number 0, a duplicate live number and a duration outside the list', function (): void {
    $w = SF::world();
    [$slot] = SF::slot($w);
    SF::script($slot);
    $row = fn (array $over) => DB::table('wave_scripts')->insert($over + [
        'ulid' => (string) Str::ulid(), 'agency_id' => $w['agency']->id, 'wave_id' => $w['wave']->id, 'market_id' => $slot->market_id,
        'creator_id' => $slot->creator_id, 'wave_creator_id' => $slot->id, 'number' => 2, 'duration_seconds' => 30, 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(fn () => DB::transaction(fn () => $row(['number' => 4])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => $row(['number' => 0])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => $row(['number' => 1])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => $row(['duration_seconds' => 25])))->toThrow(QueryException::class);
    expect($row([]))->toBeTrue();
});

it('G4e: a number is unique among the LIVE scripts of a creator only: an archived script holds none, and a restore into a taken number is refused', function (): void {
    $w = SF::world();
    [$slot] = SF::slot($w);
    $one = SF::script($slot);                         // live number 1
    $row = fn (array $over) => DB::table('wave_scripts')->insert($over + [
        'ulid' => (string) Str::ulid(), 'agency_id' => $w['agency']->id, 'wave_id' => $w['wave']->id, 'market_id' => $slot->market_id,
        'creator_id' => $slot->creator_id, 'wave_creator_id' => null, 'archived_at' => now(), 'number' => 1, 'duration_seconds' => 30, 'created_at' => now(), 'updated_at' => now(),
    ]);

    // two archived scripts with the same number as a live one, and as each other: all fine
    expect($row([]))->toBeTrue()->and($row([]))->toBeTrue();
    expect(WaveScript::query()->where('creator_id', $slot->creator_id)->where('number', 1)->count())->toBe(3);

    // a LIVE duplicate is still refused, and so is bringing an archived script back onto a number a live one holds
    expect(fn () => DB::transaction(fn () => $row(['wave_creator_id' => $slot->id, 'archived_at' => null])))->toThrow(QueryException::class);
    $archived = WaveScript::query()->whereNotNull('archived_at')->firstOrFail();
    expect(fn () => DB::transaction(fn () => DB::table('wave_scripts')->where('id', $archived->id)->update(['wave_creator_id' => $slot->id, 'archived_at' => null])))->toThrow(QueryException::class);
    // archiving the live one frees the number
    DB::table('wave_scripts')->where('id', $one->id)->update(['wave_creator_id' => null, 'archived_at' => now()]);
    expect(DB::table('wave_scripts')->where('id', $archived->id)->update(['wave_creator_id' => $slot->id, 'archived_at' => null]))->toBe(1);
});

it('G5: a script points at a slot of its own wave, market AND creator; the archived CHECK; the hook and text CHECKs', function (): void {
    $w = SF::world();
    [$slot] = SF::slot($w);
    [$other] = SF::slot($w, 'RO');
    $row = fn (array $over) => DB::transaction(fn () => DB::table('wave_scripts')->insert($over + [
        'ulid' => (string) Str::ulid(), 'agency_id' => $w['agency']->id, 'wave_id' => $w['wave']->id, 'market_id' => $slot->market_id,
        'creator_id' => $slot->creator_id, 'wave_creator_id' => $slot->id, 'number' => 1, 'duration_seconds' => 30, 'created_at' => now(), 'updated_at' => now(),
    ]));

    // another creator, another market, another wave's id: all refused by the four-column key
    expect(fn () => $row(['creator_id' => $other->creator_id]))->toThrow(QueryException::class);
    expect(fn () => $row(['market_id' => $other->market_id]))->toThrow(QueryException::class);
    expect(fn () => $row(['wave_id' => $w['otherWave']->id, 'agency_id' => $w['otherAgency']->id]))->toThrow(QueryException::class);
    // archived and slotted at once; neither archived nor slotted
    expect(fn () => $row(['archived_at' => now()]))->toThrow(QueryException::class);
    expect(fn () => $row(['wave_creator_id' => null]))->toThrow(QueryException::class);
    expect($row([]))->toBeTrue();
    expect($row(['wave_creator_id' => null, 'archived_at' => now(), 'number' => 1]))->toBeTrue();   // an archived duplicate of a live number is allowed

    $script = WaveScript::query()->where('number', 1)->firstOrFail();
    $hook = fn (array $over) => DB::transaction(fn () => DB::table('wave_script_hooks')->insert($over + [
        'ulid' => (string) Str::ulid(), 'agency_id' => $w['agency']->id, 'wave_id' => $w['wave']->id, 'script_id' => $script->id, 'position' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]));
    expect(fn () => $hook(['shot_type' => 'dolly_zoom']))->toThrow(QueryException::class);
    expect(fn () => $hook(['wave_id' => $w['otherWave']->id, 'agency_id' => $w['otherAgency']->id]))->toThrow(QueryException::class);
    expect($hook(['shot_type' => 'creator_to_camera']))->toBeTrue();

    $hookId = WaveScriptHook::query()->where('script_id', $script->id)->value('id');
    $text = fn (array $over) => DB::transaction(fn () => DB::table('wave_script_hook_texts')->insert($over + [
        'agency_id' => $w['agency']->id, 'wave_id' => $w['wave']->id, 'script_id' => $script->id, 'hook_id' => $hookId,
        'field' => 'audio', 'language' => 'en', 'body' => 'x', 'origin' => 'manual', 'created_at' => now(), 'updated_at' => now(),
    ]));
    expect(fn () => $text(['field' => 'voiceover']))->toThrow(QueryException::class);
    expect(fn () => $text(['origin' => 'ai']))->toThrow(QueryException::class);
    expect(fn () => $text(['body' => '   ']))->toThrow(QueryException::class);
    expect($text([]))->toBeTrue();
    expect(fn () => $text([]))->toThrow(QueryException::class);   // unique (hook, field, language)
    // a hook of ANOTHER script is refused by the (script_id, hook_id) key
    $otherScript = SF::script($other);
    expect(fn () => $text(['script_id' => $otherScript->id, 'field' => 'overlay']))->toThrow(QueryException::class);
});

it('G13: two connections save the same hook at the same revision: one wins, one gets the conflict, one text is stored', function (): void {
    $w = SF::world();
    [$slot] = SF::slot($w);
    $script = SF::script($slot);
    $hook = SF::hooksOf($script)[0];

    $results = scriptsRace($w, ['mode' => 'hook', 'script' => $script->ulid, 'hook' => $hook->ulid, 'revision' => 1], [['text' => 'from A'], ['text' => 'from B']]);

    expect($results)->toBe(['ok', 'wave_script.conflict'])
        ->and(WaveScriptHookText::query()->where('hook_id', $hook->id)->count())->toBe(1)
        ->and(WaveScriptHook::query()->whereKey($hook->id)->value('revision'))->toBe(2);
});

it('G16: the Notes step CHECK accepts scripts and still refuses any other step', function (): void {
    $w = SF::world();
    $row = fn (string $step) => DB::table('wave_strategy_notes')->insert([
        'ulid' => (string) Str::ulid(), 'agency_id' => $w['agency']->id, 'wave_id' => $w['wave']->id,
        'market_id' => $w['mkid']['SK'], 'step' => $step, 'body' => 'x', 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect($row('scripts'))->toBeTrue();
    expect(fn () => $row('bogus'))->toThrow(QueryException::class);
});

it('G17c: the database is the backstop: a market or a slot with scripts cannot be deleted by hand', function (): void {
    $w = SF::world();
    [$slot] = SF::slot($w);
    SF::script($slot);
    $archived = F::slot($w, Creator::factory()->approved()->create(), 'RO');
    SF::script($archived);
    S::call($w['admin'], 'DELETE', F::url($w, $archived->ulid), ['scripts' => 'archive'])->assertOk();

    expect(fn () => DB::transaction(fn () => DB::table('wave_creators')->where('id', $slot->id)->delete()))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('wave_markets')->where('id', $archived->market_id)->delete()))->toThrow(QueryException::class);
});

it('G9: Replace moves the scripts with the slot in the database (ON UPDATE CASCADE), and the number holds', function (): void {
    $w = SF::world();
    [$slot] = SF::slot($w);
    $script = SF::script($slot);
    $new = F::applicant($w['campaign']);

    S::call($w['admin'], 'POST', F::url($w, "{$slot->ulid}/replace"), ['creator' => $new->ulid])->assertOk();

    expect(WaveScript::query()->whereKey($script->id)->firstOrFail()->only(['creator_id', 'wave_creator_id', 'number']))->toBe(['creator_id' => $new->id, 'wave_creator_id' => $slot->id, 'number' => 1]);
});
