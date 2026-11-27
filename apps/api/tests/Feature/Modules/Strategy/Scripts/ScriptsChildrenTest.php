<?php

declare(strict_types=1);

use App\Modules\Strategy\Models\WaveCreator;
use App\Modules\Strategy\Models\WaveScript;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatorFixtures as F;
use Tests\Support\ScriptFixtures as SF;
use Tests\Support\StrategyFixtures as S;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * PL-3.2b A1 (ruling 11): archive, restore, Remove and Replace carry the lines, B-roll, texts and images with the script (the
 * counts are equal before and after); permanent delete removes every child and every stored object, objects first.
 */

beforeEach(fn () => Storage::fake('media'));

const PL32B_CHILD_TABLES = ['wave_script_hooks', 'wave_script_hook_texts', 'wave_script_lines', 'wave_script_line_texts', 'wave_script_broll', 'wave_script_images'];

/** @return array<string, int> */
function pl32bCounts(): array
{
    $out = [];
    foreach (PL32B_CHILD_TABLES as $table) {
        $out[$table] = DB::table($table)->count();
    }

    return $out;
}

/**
 * A script with everything on it: hooks with text, two lines with text in two languages and three B-roll rows, an image on a hook
 * and one on a line.
 *
 * @param  array<string, mixed>  $w
 * @return array{0: string, 1: WaveCreator, 2: array<string, int>}
 */
function pl32bFullScript(array $w): array
{
    SF::languages($w, 'SK', ['sk'])->assertOk();
    [$slot] = SF::slot($w);
    $id = SF::api($w, 'SK', $slot);
    $script = WaveScript::query()->where('ulid', $id)->firstOrFail();
    SF::text(SF::hooksOf($script)[0], 'audio', 'Hook words');
    SF::text(SF::hooksOf($script)[0], 'audio', 'Slová', 'sk');
    $a = SF::line($script, 0, 'First line', 2);
    $b = SF::line($script, 1, 'Second line', 1);
    SF::lineText($a, 'Prvý riadok', 'sk');
    SF::upload($w, $id, 'hook', SF::hooksOf($script)[0]->ulid, SF::png())->assertCreated();
    SF::upload($w, $id, 'line', $b->ulid, SF::png(), 'image/png', 'b.png')->assertCreated();

    return [$id, $slot, pl32bCounts()];
}

it('A1: the full script has the children the test counts', function (): void {
    $w = SF::world();
    [, , $counts] = pl32bFullScript($w);

    expect($counts)->toBe(['wave_script_hooks' => 3, 'wave_script_hook_texts' => 2, 'wave_script_lines' => 2, 'wave_script_line_texts' => 3, 'wave_script_broll' => 3, 'wave_script_images' => 2])
        ->and(Storage::disk('media')->allFiles())->toHaveCount(4);
});

it('A1: archive and restore keep every child and every stored object', function (): void {
    $w = SF::world();
    [$id, , $counts] = pl32bFullScript($w);
    $objects = Storage::disk('media')->allFiles();

    S::call($w['admin'], 'POST', SF::url($w, "{$id}/archive"))->assertOk();
    expect(pl32bCounts())->toBe($counts)->and(Storage::disk('media')->allFiles())->toBe($objects);

    // The archived list says what goes with a script (the dialogs name lines and images where they name hooks).
    $listed = S::call($w['admin'], 'GET', SF::url($w, 'archived').'?market='.$w['mk']['SK'])->assertOk()->json('data.0');
    expect($listed)->toMatchArray(['id' => $id, 'hooks' => 3, 'lines' => 2, 'images' => 2]);

    S::call($w['admin'], 'POST', SF::url($w, "archived/{$id}/restore"))->assertOk();
    expect(pl32bCounts())->toBe($counts)->and(Storage::disk('media')->allFiles())->toBe($objects);

    $shown = S::call($w['staff'], 'GET', SF::url($w, $id))->assertOk();
    expect($shown->json('data.lines'))->toHaveCount(2)->and($shown->json('data.lines.0.broll'))->toHaveCount(2)->and($shown->json('data.lines.0.texts'))->toBe(['en' => 'First line', 'sk' => 'Prvý riadok'])
        ->and($shown->json('data.lines.1.images'))->toHaveCount(1)->and($shown->json('data.hooks.0.images'))->toHaveCount(1);
});

it('A1: the archived list counts each script\'s own lines and READY images (a reservation that was never completed is not an image)', function (): void {
    $w = SF::world();
    [$full] = pl32bFullScript($w); // 3 hooks, 2 lines, 2 ready images
    [$slot] = SF::slot($w);
    $other = SF::api($w, 'SK', $slot);
    $script = WaveScript::query()->where('ulid', $other)->firstOrFail();
    SF::line($script, 0, 'One');
    SF::line($script, 1, 'Two');
    SF::line($script, 2, 'Three');
    // reserved but never completed: it must not be counted
    S::call($w['staff'], 'POST', SF::url($w, "{$other}/images/uploads/init"), [
        'block' => 'line', 'id' => $script->lines()->firstOrFail()->ulid, 'mime_type' => 'image/png', 'declared_bytes' => 100, 'original_filename' => 'later.png',
    ])->assertCreated();
    SF::rearm();

    S::call($w['admin'], 'POST', SF::url($w, "{$full}/archive"))->assertOk();
    S::call($w['admin'], 'POST', SF::url($w, "{$other}/archive"))->assertOk();

    $data = S::call($w['admin'], 'GET', SF::url($w, 'archived').'?market='.$w['mk']['SK'])->assertOk()->json('data');
    $rows = array_combine(array_column($data, 'id'), $data);
    expect($rows)->toHaveCount(2)
        ->and($rows[$full])->toMatchArray(['hooks' => 3, 'lines' => 2, 'images' => 2])
        ->and($rows[$other])->toMatchArray(['hooks' => 3, 'lines' => 3, 'images' => 0]);
});

it('A1: an archived script is read-only: its lines, B-roll and images cannot be changed or added until it is restored', function (): void {
    $w = SF::world();
    [$id] = pl32bFullScript($w);
    $line = WaveScript::query()->where('ulid', $id)->firstOrFail()->lines()->orderBy('position')->firstOrFail();
    S::call($w['admin'], 'POST', SF::url($w, "{$id}/archive"))->assertOk();
    $counts = pl32bCounts();

    foreach ([
        ['POST', "{$id}/lines", []],
        ['PUT', "{$id}/lines/{$line->ulid}", ['texts' => ['en' => 'x'], 'revision' => 1]],
        ['DELETE', "{$id}/lines/{$line->ulid}", []],
        ['POST', "{$id}/lines/{$line->ulid}/broll", []],
        ['POST', "{$id}/images/uploads/init", ['block' => 'line', 'id' => $line->ulid, 'mime_type' => 'image/png', 'declared_bytes' => 10, 'original_filename' => 'a.png']],
    ] as [$method, $path, $body]) {
        expect(S::call($w['admin'], $method, SF::url($w, $path), $body)->getStatusCode())->toBeIn([404, 409, 422]);
    }
    expect(pl32bCounts())->toBe($counts);
});

it('A1: Remove with "archive" keeps every child; Remove with "delete" removes every child and every object', function (): void {
    $w = SF::world();
    [, $slot, $counts] = pl32bFullScript($w);
    $objects = Storage::disk('media')->allFiles();

    S::call($w['admin'], 'DELETE', F::url($w, $slot->ulid), ['scripts' => 'archive'])->assertOk();
    expect(pl32bCounts())->toBe($counts)->and(Storage::disk('media')->allFiles())->toBe($objects);

    $w2 = SF::world();
    [, $slot2] = pl32bFullScript($w2);
    S::call($w2['admin'], 'DELETE', F::url($w2, $slot2->ulid), ['scripts' => 'delete'])->assertOk();
    expect(array_sum(pl32bCounts()))->toBe(array_sum($counts))->and(DB::table('wave_scripts')->count())->toBe(1)
        ->and(Storage::disk('media')->allFiles())->toHaveCount(4);
});

it('A1: Replace carries the scripts with the slot and every child with them', function (): void {
    $w = SF::world();
    [$id, $slot, $counts] = pl32bFullScript($w);
    $objects = Storage::disk('media')->allFiles();
    $new = F::applicant($w['campaign']);

    S::call($w['admin'], 'POST', F::url($w, "{$slot->ulid}/replace"), ['creator' => $new->ulid])->assertOk();

    expect(WaveScript::query()->where('ulid', $id)->value('creator_id'))->toBe($new->id)->and(pl32bCounts())->toBe($counts)->and(Storage::disk('media')->allFiles())->toBe($objects);
});

it('A1: permanent delete of an archived script removes every child row and every stored object', function (): void {
    $w = SF::world();
    [$id] = pl32bFullScript($w);
    S::call($w['admin'], 'POST', SF::url($w, "{$id}/archive"))->assertOk();

    S::call($w['manager'], 'DELETE', SF::url($w, "archived/{$id}"))->assertOk();

    expect(array_sum(pl32bCounts()))->toBe(0)->and(Storage::disk('media')->allFiles())->toBe([])->and(DB::table('wave_scripts')->count())->toBe(0);
});

it('A1: a permanent delete whose stored objects cannot be deleted changes nothing and says so; the retry works', function (): void {
    $w = SF::world();
    [$id, , $counts] = pl32bFullScript($w);
    S::call($w['admin'], 'POST', SF::url($w, "{$id}/archive"))->assertOk();
    $real = Storage::disk('media');
    $objects = $real->allFiles();

    $failing = Mockery::mock($real)->makePartial();
    $failing->shouldReceive('delete')->andReturn(false);
    Storage::set('media', $failing);
    S::call($w['admin'], 'DELETE', SF::url($w, "archived/{$id}"))->assertStatus(500);
    Storage::set('media', $real);
    SF::rearm();

    expect(pl32bCounts())->toBe($counts)->and(WaveScript::query()->where('ulid', $id)->exists())->toBeTrue()->and($real->allFiles())->toBe($objects);
    S::call($w['admin'], 'DELETE', SF::url($w, "archived/{$id}"))->assertOk();
    expect(array_sum(pl32bCounts()))->toBe(0)->and($real->allFiles())->toBe([]);
});
