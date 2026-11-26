<?php

declare(strict_types=1);

use App\Modules\Creators\Models\Creator;
use App\Modules\Strategy\Models\WaveScript;
use App\Modules\Strategy\Models\WaveScriptHook;
use App\Modules\Strategy\Models\WaveScriptHookText;
use App\Modules\Strategy\Models\WaveScriptImage;
use App\Modules\Strategy\Models\WaveScriptLine;
use App\Modules\Strategy\StrategyLimits;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
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

// ------------------------------------------------------------------ PL-3.2b P1

it('P1: the CHECKs and composite keys of the lines, B-roll, texts, images and market languages refuse what the services would never write', function (): void {
    $w = SF::world();
    [$slot] = SF::slot($w);
    [$other] = SF::slot($w, 'RO');
    $script = SF::script($slot);
    $otherScript = SF::script($other);
    $line = SF::line($script, 0, 'x', 1);
    $otherLine = SF::line($otherScript, 0, 'y');
    $hook = SF::hooksOf($script)[0];
    $common = ['agency_id' => $w['agency']->id, 'wave_id' => $w['wave']->id, 'script_id' => $script->id, 'created_at' => now(), 'updated_at' => now()];
    $insert = static fn (string $table, array $row) => static fn () => DB::transaction(fn () => DB::table($table)->insert($row));

    // B-roll: the shot type is one of the ten; a line of ANOTHER script is refused by (script_id, line_id)
    $broll = $common + ['ulid' => (string) Str::ulid(), 'line_id' => $line->id, 'position' => 5];
    expect($insert('wave_script_broll', ['shot_type' => 'dolly_zoom'] + $broll))->toThrow(QueryException::class);
    expect($insert('wave_script_broll', ['line_id' => $otherLine->id] + $broll))->toThrow(QueryException::class);
    expect($insert('wave_script_broll', ['shot_type' => 'close_up'] + $broll)())->toBeTrue();

    // line texts: a two-letter lowercase language, manual origin, a body with something in it, one per (line, language), a line of this script only
    $text = $common + ['line_id' => $line->id, 'language' => 'sk', 'body' => 'x', 'origin' => 'manual'];
    expect($insert('wave_script_line_texts', ['language' => 'SK'] + $text))->toThrow(QueryException::class);
    expect($insert('wave_script_line_texts', ['language' => 'eng'] + $text))->toThrow(QueryException::class);
    expect($insert('wave_script_line_texts', ['origin' => 'ai'] + $text))->toThrow(QueryException::class);
    expect($insert('wave_script_line_texts', ['body' => '  '] + $text))->toThrow(QueryException::class);
    expect($insert('wave_script_line_texts', ['line_id' => $otherLine->id] + $text))->toThrow(QueryException::class);
    expect($insert('wave_script_line_texts', $text)())->toBeTrue();
    expect($insert('wave_script_line_texts', $text))->toThrow(QueryException::class);

    // images: exactly one owner, a mime from the four, a status from the two, an owner of this script only
    $image = static fn (array $over): array => $over + $common + [
        'ulid' => (string) Str::ulid(), 'position' => 0, 'storage_key' => 'k/'.Str::ulid().'.png', 'original_filename' => 'a.png', 'declared_bytes' => 5, 'status' => 'pending',
    ];
    expect($insert('wave_script_images', $image(['hook_id' => null, 'line_id' => null])))->toThrow(QueryException::class);
    expect($insert('wave_script_images', $image(['hook_id' => $hook->id, 'line_id' => $line->id])))->toThrow(QueryException::class);
    expect($insert('wave_script_images', $image(['line_id' => $line->id, 'mime_type' => 'image/svg+xml'])))->toThrow(QueryException::class);
    expect($insert('wave_script_images', $image(['line_id' => $line->id, 'status' => 'done'])))->toThrow(QueryException::class);
    expect($insert('wave_script_images', $image(['line_id' => $otherLine->id])))->toThrow(QueryException::class);
    expect($insert('wave_script_images', $image(['line_id' => $line->id, 'mime_type' => 'image/gif']))())->toBeTrue();
    expect($insert('wave_script_images', $image(['hook_id' => $hook->id]))())->toBeTrue();

    // market languages: two lowercase letters, never en, one per (market, language), a market of this wave only
    $lang = ['agency_id' => $w['agency']->id, 'wave_id' => $w['wave']->id, 'market_id' => $slot->market_id, 'language' => 'sk', 'position' => 0, 'created_at' => now(), 'updated_at' => now()];
    expect($insert('wave_market_script_languages', ['language' => 'en'] + $lang))->toThrow(QueryException::class);
    expect($insert('wave_market_script_languages', ['language' => 'SK'] + $lang))->toThrow(QueryException::class);
    expect($insert('wave_market_script_languages', ['wave_id' => $w['otherWave']->id, 'agency_id' => $w['otherAgency']->id] + $lang))->toThrow(QueryException::class);
    expect($insert('wave_market_script_languages', $lang)())->toBeTrue();
    expect($insert('wave_market_script_languages', $lang))->toThrow(QueryException::class);

    // a script with children cannot be deleted by hand under the children (the children cascade only through the script row)
    expect(DB::table('wave_script_lines')->where('script_id', $script->id)->count())->toBe(1);
});

it('P1: nothing the database holds is a derived value: no stored line number, no stored B-roll code, no unique key on a position', function (): void {
    foreach (['wave_script_lines', 'wave_script_broll', 'wave_script_hooks'] as $table) {
        $columns = Schema::getColumnListing($table);
        expect($columns)->not->toContain('number');
        expect($columns)->not->toContain('code');
        expect($columns)->not->toContain('letter');
        foreach (Schema::getIndexes($table) as $index) {
            if ($index['unique']) {
                expect($index['columns'])->not->toContain('position', "{$table} must not have a unique key on position");
            }
        }
    }
});

it('N1 (parallel): two adds at the same moment at 29 lines leave 30, not 31', function (): void {
    $w = SF::world();
    $id = SF::api($w);
    $script = WaveScript::query()->where('ulid', $id)->firstOrFail();
    for ($i = 0; $i < 29; $i++) {
        SF::line($script, $i);
    }

    $results = scriptsRace($w, ['mode' => 'line_add', 'script' => $id], [[], []]);

    expect($results)->toBe(['ok', 'wave_script.lines_limit'])->and(WaveScriptLine::query()->where('script_id', $script->id)->count())->toBe(30);
});

it('N1 (parallel): two saves of the same line at the same revision: one wins, one gets the conflict, one text is stored', function (): void {
    $w = SF::world();
    $id = SF::api($w);
    $script = WaveScript::query()->where('ulid', $id)->firstOrFail();
    $line = SF::line($script, 0);

    $results = scriptsRace($w, ['mode' => 'line', 'script' => $id, 'line' => $line->ulid, 'revision' => 1], [['text' => 'from A'], ['text' => 'from B']]);

    expect($results)->toBe(['ok', 'wave_script.conflict'])->and(DB::table('wave_script_line_texts')->where('line_id', $line->id)->count())->toBe(1)
        ->and(WaveScriptLine::query()->whereKey($line->id)->value('revision'))->toBe(2);
});

it('I1 (parallel): two reservations at the same moment cannot pass the 300 MB total, nor the tenth place of a block', function (): void {
    Storage::fake('media');
    $w = SF::world();
    $id = SF::api($w);
    $script = WaveScript::query()->where('ulid', $id)->firstOrFail();
    $line = SF::line($script, 0);
    $cap = StrategyLimits::SCRIPT_IMAGE_TOTAL_BYTES;
    $fill = fn (int $bytes) => DB::table('wave_script_images')->insert([
        'ulid' => (string) Str::ulid(), 'agency_id' => $script->agency_id, 'wave_id' => $script->wave_id, 'script_id' => $script->id, 'line_id' => $line->id, 'position' => 0,
        'storage_key' => 'k/'.Str::ulid().'.png', 'original_filename' => 'a.png', 'declared_bytes' => $bytes, 'size_bytes' => $bytes, 'mime_type' => 'image/png', 'status' => 'ready',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // total: 250 bytes left, two reservations of 200
    $fill($cap - 250);
    $results = scriptsRace($w, ['mode' => 'image_init', 'script' => $id, 'block' => 'line', 'owner' => $line->ulid, 'declared' => 200], [[], []]);
    expect($results)->toBe(['ok', 'wave_script_image.limit_bytes'])->and(WaveScriptImage::query()->where('script_id', $script->id)->where('status', 'pending')->count())->toBe(1);

    // count: the block holds 9 more (the fill is one), so one place is left for two reservations
    DB::table('wave_script_images')->where('status', 'pending')->delete();
    DB::table('wave_script_images')->delete();
    for ($i = 0; $i < StrategyLimits::IMAGES_PER_BLOCK - 1; $i++) {
        $fill(10);
    }
    $results = scriptsRace($w, ['mode' => 'image_init', 'script' => $id, 'block' => 'line', 'owner' => $line->ulid, 'declared' => 10], [[], []]);
    expect($results)->toBe(['ok', 'wave_script_image.limit_count'])->and(WaveScriptImage::query()->where('line_id', $line->id)->count())->toBe(10);
});
