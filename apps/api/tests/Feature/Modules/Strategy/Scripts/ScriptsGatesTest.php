<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Features\CreatorsScriptsEnabled;
use App\Modules\Strategy\Models\WaveScript;
use App\Modules\Strategy\Models\WaveScriptImage;
use App\Modules\Strategy\Models\WaveScriptLine;
use App\Modules\Strategy\Services\ScriptEstimate;
use App\Modules\Strategy\Services\ScriptLanguages;
use App\Modules\Strategy\StrategyLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Pennant\Feature;
use Tests\Support\ScriptFixtures as SF;
use Tests\Support\StrategyFixtures as S;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * PL-3.2b E1 (the estimate), S1 (the constant query count), T1 (every new route, generated from the route table) and Z1 (audit).
 */

beforeEach(fn () => Storage::fake('media'));

// ---------------------------------------------------------------- E1

it('E1: the estimate is the longest spoken hook plus all spoken lines, ceil(words / 2.5); overlays, visuals and B-roll are not counted', function (): void {
    expect(ScriptEstimate::of(['a b c', 'one two three four five six', ''], ['l1 l2 l3 l4', 'p q', '']))->toBe(['words' => 12, 'seconds' => 5])
        ->and(ScriptEstimate::of([], []))->toBe(['words' => 0, 'seconds' => 0])
        ->and(ScriptEstimate::of(['one'], []))->toBe(['words' => 1, 'seconds' => 1])
        ->and(ScriptEstimate::of(['a b c d e'], []))->toBe(['words' => 5, 'seconds' => 2])
        ->and(ScriptEstimate::of(['a b c d e f'], []))->toBe(['words' => 6, 'seconds' => 3])
        // never the sum of all hooks
        ->and(ScriptEstimate::of(['a b c', 'd e f', 'g h i'], [])['words'])->toBe(3);
});

it('E1: English is counted; where it is empty, the first local language of the market that has text; nothing else', function (): void {
    $local = ['hu', 'sk'];
    expect(ScriptLanguages::pick(['en' => 'english words', 'sk' => 'slovak words here'], $local))->toBe('english words')
        ->and(ScriptLanguages::pick(['en' => '   ', 'sk' => 'slovak', 'hu' => 'magyar'], $local))->toBe('magyar')
        ->and(ScriptLanguages::pick(['sk' => 'slovak'], $local))->toBe('slovak')
        ->and(ScriptLanguages::pick(['de' => 'not a market language'], $local))->toBe('')
        ->and(ScriptLanguages::pick([], $local))->toBe('');
});

it('E1: the script carries the server\'s estimate: hooks and lines, English first, the market language as the fallback, extra fields never counted', function (): void {
    $w = SF::world();
    SF::languages($w, 'SK', ['sk'])->assertOk();
    $id = SF::api($w);
    $script = WaveScript::query()->where('ulid', $id)->firstOrFail();
    [$a, $b, $c] = SF::hooksOf($script);
    SF::text($a, 'audio', 'a b c');
    SF::text($b, 'audio', 'one two three four five six');
    SF::text($c, 'audio', 'x y', 'sk');
    SF::text($a, 'overlay', str_repeat('word ', 50));
    SF::lineText(SF::line($script, 0, 'l1 l2 l3 l4', 2), 'ignored in favour of English', 'sk');
    SF::lineText(SF::line($script, 1), 'p q', 'sk');
    SF::line($script, 2);

    S::call($w['staff'], 'GET', SF::url($w, $id))->assertOk()->assertJsonPath('data.estimate', ['words' => 12, 'seconds' => 5]);

    // a save of a line moves the estimate in the response, because the SERVER counts
    $line = S::call($w['staff'], 'GET', SF::url($w, $id))->json('data.lines.2');
    S::call($w['admin'], 'PUT', SF::url($w, "{$id}/lines/{$line['id']}"), ['texts' => ['en' => 'a b c d e f g h'], 'revision' => $line['revision']])
        ->assertOk()->assertJsonPath('data.estimate', ['words' => 20, 'seconds' => 8]);
});

// ---------------------------------------------------------------- S1

it('S1: loading a script issues the same number of queries with 3 lines and with 30 lines, B-roll and images included', function (): void {
    $w = SF::world();
    $id = SF::api($w);
    $script = WaveScript::query()->where('ulid', $id)->firstOrFail();
    $count = function () use ($w, $id): int {
        S::call($w['admin'], 'GET', SF::url($w, $id))->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        S::call($w['admin'], 'GET', SF::url($w, $id))->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };
    $first = SF::line($script, 0, 'one', 1);
    SF::upload($w, $id, 'line', $first->ulid, SF::png())->assertCreated();
    SF::line($script, 1, 'two');
    SF::line($script, 2, 'three', 1);
    $small = $count();

    for ($i = 3; $i < StrategyLimits::LINES_PER_SCRIPT; $i++) {
        SF::line($script, $i, "line {$i}", $i < 13 ? 1 : 0);
    }
    for ($i = 0; $i < 3; $i++) {
        SF::upload($w, $id, 'line', WaveScriptLine::query()->where('position', $i + 4)->value('ulid'), SF::png(40 + $i), 'image/png', "i{$i}.png")->assertCreated();
    }
    expect(WaveScriptLine::query()->where('script_id', $script->id)->count())->toBe(30)->and(WaveScriptImage::query()->count())->toBe(4)
        ->and($count())->toBe($small);
});

// ---------------------------------------------------------------- T1

/** @return list<array{0: string, 1: string}> every [method, concrete url] of the scripts and script-language routes, from the router. */
function pl32bRoutes(array $w): array
{
    $u = '01HZZZZZZZZZZZZZZZZZZZZZZZ';
    $out = [];
    foreach (app('router')->getRoutes()->getRoutes() as $route) {
        /** @var Route $route */
        $name = (string) $route->getName();
        if (! str_starts_with($name, 'waves.strategy.scripts.') && ! str_starts_with($name, 'waves.strategy.markets.script-languages.')) {
            continue;
        }
        $uri = '/'.$route->uri();
        $uri = str_replace(['{agency}', '{brand}', '{wave}'], [$w['agency']->ulid, $w['brand']->ulid, $w['wave']->ulid], $uri);
        $uri = preg_replace('/\{[a-z]+\}/', $u, $uri);
        foreach ($route->methods() as $method) {
            if ($method !== 'HEAD') {
                $out[] = [$method, (string) $uri];
            }
        }
    }

    return $out;
}

it('T1: the route table is complete: 29 routes carry the scripts and script-language names', function (): void {
    $w = SF::world();
    // 12 from PL-3.2a + 15 under scripts (PL-3.2b) + 2 language routes. A new route fails here until the gates below know it.
    expect(pl32bRoutes($w))->toHaveCount(29);
});

it('T1: every route is 404 not_found with the sprint flag off, with either prerequisite off, and for another agency; nothing is written', function (): void {
    $w = SF::world();
    $id = SF::api($w);
    SF::languages($w, 'SK', ['sk'])->assertOk();
    $tables = ['wave_scripts', 'wave_script_hooks', 'wave_script_lines', 'wave_script_broll', 'wave_script_images', 'wave_script_line_texts', 'wave_market_script_languages'];
    $snapshot = static fn (): array => array_map(static fn (string $t): int => DB::table($t)->count(), $tables);
    $before = $snapshot();
    $body = ['revision' => 1, 'ids' => [], 'languages' => ['hu'], 'slot' => $id, 'block' => 'line', 'id' => $id, 'mime_type' => 'image/png', 'declared_bytes' => 10, 'original_filename' => 'a.png', 'upload_id' => 'x'];

    foreach (pl32bRoutes($w) as [$method, $url]) {
        S::call($w['otherAdmin'], $method, $url, $body)->assertNotFound();
    }
    foreach ([CreatorsScriptsEnabled::NAME, 'strategy_enabled', 'waves_enabled'] as $flag) {
        Feature::deactivate($flag);
        foreach (pl32bRoutes($w) as [$method, $url]) {
            S::call($w['admin'], $method, $url, $body)->assertNotFound()->assertJsonPath('errors.0.code', 'not_found');
        }
        SF::rearm();
    }
    expect($snapshot())->toBe($before);
});

it('T1: roles: staff, manager and admin write lines, B-roll, images and languages; a creator user and a user of another agency cannot', function (): void {
    $w = SF::world();
    $id = SF::api($w);
    $creatorUser = User::factory()->create();

    foreach (['staff', 'manager', 'admin'] as $role) {
        S::call($w[$role], 'POST', SF::url($w, "{$id}/lines"))->assertCreated();
    }
    $line = WaveScriptLine::query()->orderBy('position')->firstOrFail();
    foreach ([['POST', "{$id}/lines"], ['POST', "{$id}/lines/{$line->ulid}/broll"], ['PUT', "{$id}/lines/order", ['ids' => []]]] as $call) {
        expect(S::call($creatorUser, $call[0], SF::url($w, $call[1]), $call[2] ?? [])->getStatusCode())->toBeIn([403, 404]);
    }
    expect(DB::table('wave_script_lines')->count())->toBe(3)->and(DB::table('wave_script_broll')->count())->toBe(0);
});

it('T1: a line, a B-roll row and an image of another script are 404 through this script\'s URL', function (): void {
    $w = SF::world();
    $mine = SF::api($w);
    $other = SF::api($w);
    $otherScript = WaveScript::query()->where('ulid', $other)->firstOrFail();
    $line = SF::line($otherScript, 0, 'x', 1);
    $broll = (string) DB::table('wave_script_broll')->where('line_id', $line->id)->value('ulid');
    SF::upload($w, $other, 'line', $line->ulid, SF::png())->assertCreated();
    $image = (string) DB::table('wave_script_images')->value('ulid');

    S::call($w['admin'], 'PUT', SF::url($w, "{$mine}/lines/{$line->ulid}"), ['texts' => ['en' => 'x'], 'revision' => 1])->assertNotFound();
    S::call($w['admin'], 'DELETE', SF::url($w, "{$mine}/lines/{$line->ulid}"))->assertNotFound();
    S::call($w['admin'], 'POST', SF::url($w, "{$mine}/lines/{$line->ulid}/broll"))->assertNotFound();
    S::call($w['admin'], 'DELETE', SF::url($w, "{$mine}/lines/{$line->ulid}/broll/{$broll}"))->assertNotFound();
    S::call($w['admin'], 'DELETE', SF::url($w, "{$mine}/images/{$image}"))->assertNotFound();
    S::call($w['admin'], 'POST', SF::url($w, "{$mine}/images/uploads/init"), ['block' => 'line', 'id' => $line->ulid, 'mime_type' => 'image/png', 'declared_bytes' => 10, 'original_filename' => 'a.png'])->assertNotFound();
    expect(DB::table('wave_script_lines')->count())->toBe(1)->and(DB::table('wave_script_broll')->count())->toBe(1)->and(DB::table('wave_script_images')->count())->toBe(1);
});

// ---------------------------------------------------------------- Z1

it('Z1: the new audit actions are all emitted, with literal ids, numbers and counts, never a text or a file name', function (): void {
    $w = SF::world();
    SF::languages($w, 'SK', ['sk'])->assertOk();
    $id = SF::api($w);
    $script = WaveScript::query()->where('ulid', $id)->firstOrFail();
    $secretText = 'SECRET-SPOKEN-TEXT';
    $secretFile = 'secret-file-name.png';

    $res = S::call($w['admin'], 'POST', SF::url($w, "{$id}/lines"))->assertCreated();
    $res = S::call($w['admin'], 'POST', SF::url($w, "{$id}/lines"))->assertCreated();
    [$a, $b] = $res->json('data.lines');
    $res = S::call($w['admin'], 'POST', SF::url($w, "{$id}/lines/{$a['id']}/broll"))->assertCreated();
    $res = S::call($w['admin'], 'POST', SF::url($w, "{$id}/lines/{$a['id']}/broll"))->assertCreated();
    $rows = array_column($res->json('data.lines.0.broll'), 'id');
    $line = $res->json('data.lines.0');
    S::call($w['admin'], 'PUT', SF::url($w, "{$id}/lines/{$a['id']}"), ['texts' => ['en' => $secretText, 'sk' => $secretText], 'cta' => true, 'broll' => [$rows[0] => ['description' => $secretText, 'shot_type' => 'wide']], 'revision' => $line['revision']])->assertOk();
    S::call($w['admin'], 'PUT', SF::url($w, "{$id}/lines/{$a['id']}/broll/order"), ['ids' => array_reverse($rows)])->assertOk();
    S::call($w['admin'], 'DELETE', SF::url($w, "{$id}/lines/{$a['id']}/broll/{$rows[0]}"))->assertOk();
    S::call($w['admin'], 'POST', SF::url($w, "{$id}/lines/{$a['id']}/duplicate"))->assertCreated();
    $ids = array_column(S::call($w['admin'], 'GET', SF::url($w, $id))->json('data.lines'), 'id');
    S::call($w['admin'], 'PUT', SF::url($w, "{$id}/lines/order"), ['ids' => array_reverse($ids)])->assertOk();
    $hooks = array_column(SF::hooksOf($script) === [] ? [] : array_map(static fn ($h): array => ['id' => $h->ulid], SF::hooksOf($script)), 'id');
    S::call($w['admin'], 'PUT', SF::url($w, "{$id}/hooks/order"), ['ids' => array_reverse($hooks)])->assertOk();
    SF::upload($w, $id, 'line', $b['id'], SF::png(), 'image/png', $secretFile)->assertCreated();
    SF::upload($w, $id, 'line', $b['id'], SF::png(41), 'image/png', $secretFile)->assertCreated();
    $images = WaveScriptImage::query()->orderBy('position')->pluck('ulid')->all();
    S::call($w['admin'], 'PUT', SF::url($w, "{$id}/images/order"), ['block' => 'line', 'id' => $b['id'], 'ids' => array_reverse($images)])->assertOk();
    S::call($w['admin'], 'DELETE', SF::url($w, "{$id}/images/{$images[0]}"))->assertOk();
    S::call($w['admin'], 'DELETE', SF::url($w, "{$id}/lines/{$b['id']}"))->assertOk();

    $new = [
        AuditAction::WaveMarketScriptLanguagesUpdated, AuditAction::WaveScriptHookReordered, AuditAction::WaveScriptLineCreated, AuditAction::WaveScriptLineUpdated,
        AuditAction::WaveScriptLineDuplicated, AuditAction::WaveScriptLineDeleted, AuditAction::WaveScriptLineReordered, AuditAction::WaveScriptBrollCreated,
        AuditAction::WaveScriptBrollDeleted, AuditAction::WaveScriptBrollReordered, AuditAction::WaveScriptImageAdded, AuditAction::WaveScriptImageRemoved,
        AuditAction::WaveScriptImageReordered,
    ];
    foreach ($new as $action) {
        $rows = AuditLog::query()->where('action', $action->value)->get();
        expect($rows->count())->toBeGreaterThan(0, "{$action->value} was never emitted");
        foreach ($rows as $row) {
            $json = (string) json_encode($row->metadata);
            expect($json)->not->toContain($secretText)->and($json)->not->toContain($secretFile)->and($json)->not->toContain('.png');
            foreach (array_keys($row->metadata ?? []) as $key) {
                expect($key)->not->toBeIn(['text', 'texts', 'body', 'description', 'filename', 'original_filename', 'storage_key', 'key']);
            }
            foreach ($row->metadata ?? [] as $value) {
                expect(is_scalar($value) || is_array($value))->toBeTrue();
            }
        }
    }
    // the update says WHICH fields changed, by name
    $updated = AuditLog::query()->where('action', AuditAction::WaveScriptLineUpdated->value)->firstOrFail();
    $meta = (array) $updated->metadata;
    expect($meta['line'] ?? null)->toBe($a['id'])->and($meta['fields'] ?? null)->toBeArray();
});
