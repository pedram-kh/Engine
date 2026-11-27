<?php

declare(strict_types=1);

use App\Modules\Strategy\Models\WaveScript;
use App\Modules\Strategy\Models\WaveScriptBroll;
use App\Modules\Strategy\Models\WaveScriptHook;
use App\Modules\Strategy\Models\WaveScriptLine;
use App\Modules\Strategy\Models\WaveScriptLineText;
use App\Modules\Strategy\StrategyLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ScriptFixtures as SF;
use Tests\Support\StrategyFixtures as S;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * PL-3.2b N1, B1, R1 and the per-line save block (rulings 6, 7, 8, 12): lines numbered from 2, B-roll codes computed from the
 * position, several CTA lines, every line its own block with its own revision, reorder by the complete list.
 */

/**
 * @param  array<string, mixed>  $w
 * @param  array<string, mixed>  $body
 * @return TestResponse<Response>
 */
function pl32bCall(array $w, string $method, string $script, string $suffix, array $body = [], string $as = 'admin'): TestResponse
{
    return S::call($w[$as], $method, SF::url($w, "{$script}/{$suffix}"), $body);
}

/**
 * The B-roll of one line, as the response shows it.
 *
 * @param  TestResponse<Response>  $res
 * @return list<array<string, mixed>>
 */
function pl32bBroll(TestResponse $res, string $lineUlid): array
{
    foreach ($res->json('data.lines') as $line) {
        if ($line['id'] === $lineUlid) {
            return $line['broll'];
        }
    }

    return [];
}

/**
 * @param  TestResponse<Response>  $res
 * @return list<string>
 */
function pl32bIds(TestResponse $res, string $path = 'data.lines'): array
{
    return array_column($res->json($path), 'id');
}

it('N1: a line is numbered from 2 by its position, never stored; moving it renumbers it', function (): void {
    $w = SF::world();
    $id = SF::api($w);

    pl32bCall($w, 'POST', $id, 'lines')->assertCreated();
    $second = pl32bCall($w, 'POST', $id, 'lines')->assertCreated();
    expect(array_column($second->json('data.lines'), 'number'))->toBe([2, 3])
        ->and(Schema::hasColumn('wave_script_lines', 'shot_type'))->toBeTrue()->and(Schema::hasColumn('wave_script_lines', 'visual_description'))->toBeTrue()->and(Schema::hasColumn('wave_scripts', 'setting'))->toBeTrue()
        ->and(Schema::hasColumn('wave_script_lines', 'number'))->toBeFalse()->and(Schema::hasColumn('wave_script_broll', 'code'))->toBeFalse();

    $ids = pl32bIds($second);
    $moved = pl32bCall($w, 'PUT', $id, 'lines/order', ['ids' => [$ids[1], $ids[0]]])->assertOk();
    expect(pl32bIds($moved))->toBe([$ids[1], $ids[0]])->and(array_column($moved->json('data.lines'), 'number'))->toBe([2, 3]);
});

it('N1: several CTA lines are allowed; a new line is not a CTA', function (): void {
    $w = SF::world();
    $id = SF::api($w);
    pl32bCall($w, 'POST', $id, 'lines')->assertCreated();
    pl32bCall($w, 'POST', $id, 'lines')->assertCreated();
    pl32bCall($w, 'POST', $id, 'lines')->assertCreated();
    $lines = pl32bCall($w, 'GET', $id, '')->json('data.lines');
    expect(array_column($lines, 'cta'))->toBe([false, false, false]);

    foreach ([0, 2] as $i) {
        pl32bCall($w, 'PUT', $id, "lines/{$lines[$i]['id']}", ['cta' => true, 'revision' => $lines[$i]['revision']])->assertOk();
    }
    expect(array_column(pl32bCall($w, 'GET', $id, '')->json('data.lines'), 'cta'))->toBe([true, false, true]);
});

it('N1: a script has at most 30 lines; the 31st (new or a copy) is refused and nothing is written', function (): void {
    $w = SF::world();
    $id = SF::api($w);
    $script = WaveScript::query()->where('ulid', $id)->firstOrFail();
    for ($i = 0; $i < StrategyLimits::LINES_PER_SCRIPT; $i++) {
        SF::line($script, $i);
    }
    $first = WaveScriptLine::query()->orderBy('position')->firstOrFail();

    pl32bCall($w, 'POST', $id, 'lines')->assertStatus(422)->assertJsonPath('errors.0.code', 'wave_script.lines_limit')->assertJsonPath('errors.0.meta.max', 30);
    pl32bCall($w, 'POST', $id, "lines/{$first->ulid}/duplicate")->assertStatus(422)->assertJsonPath('errors.0.code', 'wave_script.lines_limit');
    expect(WaveScriptLine::query()->count())->toBe(30);

    pl32bCall($w, 'DELETE', $id, "lines/{$first->ulid}")->assertOk();
    pl32bCall($w, 'POST', $id, 'lines')->assertCreated();
});

it('B1: B-roll belongs to a line, is coded {line number}_{n} by position (first is 2_1), and the code follows a move of the line or of the row', function (): void {
    $w = SF::world();
    $id = SF::api($w);
    pl32bCall($w, 'POST', $id, 'lines')->assertCreated();
    $res = pl32bCall($w, 'POST', $id, 'lines')->assertCreated();
    [$a, $b] = pl32bIds($res);

    pl32bCall($w, 'POST', $id, "lines/{$a}/broll")->assertCreated();
    $res = pl32bCall($w, 'POST', $id, "lines/{$a}/broll")->assertCreated();
    pl32bCall($w, 'POST', $id, "lines/{$b}/broll")->assertCreated();

    $codes = static function (TestResponse $r): array {
        $out = [];
        foreach ($r->json('data.lines') as $l) {
            $out[$l['id']] = array_column($l['broll'], 'code');
        }

        return $out;
    };
    $res = pl32bCall($w, 'GET', $id, '');
    expect($codes($res))->toBe([$a => ['2_1', '2_2'], $b => ['3_1']]);

    // move the second line first: its B-roll becomes 2_1, the other line's become 3_n
    $res = pl32bCall($w, 'PUT', $id, 'lines/order', ['ids' => [$b, $a]])->assertOk();
    expect($codes($res))->toBe([$b => ['2_1'], $a => ['3_1', '3_2']]);

    // move a row inside its line
    $rows = array_column(pl32bBroll($res, $a), 'id');
    $res = pl32bCall($w, 'PUT', $id, "lines/{$a}/broll/order", ['ids' => array_reverse($rows)])->assertOk();
    expect(array_column(pl32bBroll($res, $a), 'id'))->toBe(array_reverse($rows));
});

it('B1: deleting an EARLIER line shifts the codes of the lines after it', function (): void {
    $w = SF::world();
    $id = SF::api($w);
    $script = WaveScript::query()->where('ulid', $id)->firstOrFail();
    $a = SF::line($script, 0, 'a', 1);
    $b = SF::line($script, 1, 'b', 2);

    $res = pl32bCall($w, 'GET', $id, '');
    expect(array_column(pl32bBroll($res, $b->ulid), 'code'))->toBe(['3_1', '3_2']);

    $res = pl32bCall($w, 'DELETE', $id, "lines/{$a->ulid}")->assertOk();
    expect(array_column($res->json('data.lines.0.broll'), 'code'))->toBe(['2_1', '2_2']);
});

it('B1: there is no B-roll on a hook (no route), and a B-roll row is addressed through ITS line only', function (): void {
    $w = SF::world();
    $id = SF::api($w);
    $hook = SF::hooksOf(WaveScript::query()->where('ulid', $id)->firstOrFail())[0];
    expect(pl32bCall($w, 'POST', $id, "hooks/{$hook->ulid}/broll")->getStatusCode())->toBeIn([404, 405]);

    $res = pl32bCall($w, 'POST', $id, 'lines')->assertCreated();
    $res = pl32bCall($w, 'POST', $id, 'lines')->assertCreated();
    [$a, $b] = pl32bIds($res);
    $res = pl32bCall($w, 'POST', $id, "lines/{$a}/broll")->assertCreated();
    $row = pl32bBroll($res, $a)[0]['id'];

    pl32bCall($w, 'DELETE', $id, "lines/{$b}/broll/{$row}")->assertNotFound();
    expect(WaveScriptBroll::query()->count())->toBe(1);
});

it('B1: the 41st B-roll row of a script is refused wherever it goes; a deleted row frees a place', function (): void {
    $w = SF::world();
    $id = SF::api($w);
    $script = WaveScript::query()->where('ulid', $id)->firstOrFail();
    $a = SF::line($script, 0, null, 25);
    $b = SF::line($script, 1, null, 15);

    foreach ([$a, $b] as $line) {
        pl32bCall($w, 'POST', $id, "lines/{$line->ulid}/broll")->assertStatus(422)->assertJsonPath('errors.0.code', 'wave_script.broll_limit')->assertJsonPath('errors.0.meta.max', 40);
    }
    expect(WaveScriptBroll::query()->count())->toBe(40);

    $row = WaveScriptBroll::query()->where('line_id', $a->id)->firstOrFail();
    pl32bCall($w, 'DELETE', $id, "lines/{$a->ulid}/broll/{$row->ulid}")->assertOk();
    pl32bCall($w, 'POST', $id, "lines/{$b->ulid}/broll")->assertCreated();
});

it('B1: a line block saves the spoken text, the CTA flag and the B-roll fields together; shot types are the list; descriptions are English text of at most 400', function (): void {
    $w = SF::world();
    $id = SF::api($w);
    pl32bCall($w, 'POST', $id, 'lines')->assertCreated();
    $res = pl32bCall($w, 'POST', $id, 'lines')->assertCreated();
    $line = $res->json('data.lines.0');
    $res = pl32bCall($w, 'POST', $id, "lines/{$line['id']}/broll")->assertCreated();
    $line = $res->json('data.lines.0');
    $row = $line['broll'][0]['id'];

    $saved = pl32bCall($w, 'PUT', $id, "lines/{$line['id']}", [
        'texts' => ['en' => "He\x07llo  there"], 'cta' => true, 'broll' => [$row => ['shot_type' => 'close_up', 'description' => 'Hands on the product']], 'revision' => $line['revision'],
    ])->assertOk();
    $out = $saved->json('data.lines.0');
    expect($out['texts'])->toBe(['en' => 'Hello  there'])->and($out['cta'])->toBeTrue()->and($out['broll'][0])->toMatchArray(['shot_type' => 'close_up', 'description' => 'Hands on the product', 'code' => '2_1'])
        ->and($out['revision'])->toBe($line['revision'] + 1);

    pl32bCall($w, 'PUT', $id, "lines/{$line['id']}", ['broll' => [$row => ['shot_type' => 'banana']], 'revision' => $out['revision']])->assertStatus(422);
    pl32bCall($w, 'PUT', $id, "lines/{$line['id']}", ['broll' => [$row => ['description' => str_repeat('x', 401)]], 'revision' => $out['revision']])->assertStatus(422);
    pl32bCall($w, 'PUT', $id, "lines/{$line['id']}", ['texts' => ['en' => str_repeat('x', 601)], 'revision' => $out['revision']])->assertStatus(422);
    // a blank text removes the row
    pl32bCall($w, 'PUT', $id, "lines/{$line['id']}", ['texts' => ['en' => '   '], 'revision' => $out['revision']])->assertOk()->assertJsonPath('data.lines.0.texts', []);
    expect(WaveScriptLineText::query()->count())->toBe(0);
});

it('the line block has its own revision: a stale save is a 409 with the current line and writes nothing; another line does not conflict; a no-change save bumps nothing', function (): void {
    $w = SF::world();
    $id = SF::api($w);
    pl32bCall($w, 'POST', $id, 'lines')->assertCreated();
    $res = pl32bCall($w, 'POST', $id, 'lines')->assertCreated();
    [$a, $b] = $res->json('data.lines');

    pl32bCall($w, 'PUT', $id, "lines/{$a['id']}", ['texts' => ['en' => 'one'], 'revision' => $a['revision']])->assertOk()->assertJsonPath('data.lines.0.revision', $a['revision'] + 1);
    $stale = pl32bCall($w, 'PUT', $id, "lines/{$a['id']}", ['texts' => ['en' => 'two'], 'revision' => $a['revision']])->assertStatus(409);
    $stale->assertJsonPath('errors.0.code', 'wave_script.conflict')->assertJsonPath('errors.0.meta.block', 'line')->assertJsonPath('errors.0.meta.current.texts.en', 'one');
    expect(WaveScriptLineText::query()->where('body', 'two')->exists())->toBeFalse();

    // line B is another block: saving it at ITS revision is fine
    pl32bCall($w, 'PUT', $id, "lines/{$b['id']}", ['texts' => ['en' => 'b text'], 'revision' => $b['revision']])->assertOk();

    // the same text again, at a stale revision: nothing to change, so nothing conflicts and nothing is bumped
    $before = WaveScriptLine::query()->where('ulid', $a['id'])->value('revision');
    pl32bCall($w, 'PUT', $id, "lines/{$a['id']}", ['texts' => ['en' => 'one'], 'revision' => $a['revision']])->assertOk();
    expect(WaveScriptLine::query()->where('ulid', $a['id'])->value('revision'))->toBe($before);
});

it('a save to a line someone deleted says so (404 wave_script_line.deleted) and writes nothing; a B-roll row deleted meanwhile is a 409 broll_gone', function (): void {
    $w = SF::world();
    $id = SF::api($w);
    $res = pl32bCall($w, 'POST', $id, 'lines')->assertCreated();
    $line = $res->json('data.lines.0');
    $res = pl32bCall($w, 'POST', $id, "lines/{$line['id']}/broll")->assertCreated();
    $line = $res->json('data.lines.0');
    $row = $line['broll'][0]['id'];

    pl32bCall($w, 'DELETE', $id, "lines/{$line['id']}/broll/{$row}", [], 'staff')->assertOk();
    // the other tab still holds the old line (revision and row set)
    $gone = pl32bCall($w, 'PUT', $id, "lines/{$line['id']}", ['broll' => [$row => ['description' => 'late']], 'revision' => $line['revision']])->assertStatus(409);
    $gone->assertJsonPath('errors.0.code', 'wave_script.broll_gone');
    expect(WaveScriptBroll::query()->count())->toBe(0);

    pl32bCall($w, 'DELETE', $id, "lines/{$line['id']}", [], 'staff')->assertOk();
    pl32bCall($w, 'PUT', $id, "lines/{$line['id']}", ['texts' => ['en' => 'late'], 'revision' => $line['revision']])
        ->assertNotFound()->assertJsonPath('errors.0.code', 'wave_script_line.deleted');
    expect(WaveScriptLineText::query()->count())->toBe(0);
    // an id that never existed is the same clear answer
    pl32bCall($w, 'PUT', $id, 'lines/'.Str::ulid(), ['texts' => ['en' => 'x'], 'revision' => 1])->assertNotFound();
});

it('R1: reorder takes the COMPLETE id list; a stale, short, long or foreign list is order_mismatch and writes nothing; no line revision moves', function (): void {
    $w = SF::world();
    $id = SF::api($w);
    $script = WaveScript::query()->where('ulid', $id)->firstOrFail();
    $x = SF::line($script, 0, 'x');
    $y = SF::line($script, 1, 'y');
    $z = SF::line($script, 2, 'z');
    $revisions = static fn (): array => WaveScriptLine::query()->orderBy('id')->pluck('revision')->all();
    $order = static fn (): array => WaveScriptLine::query()->orderBy('position')->pluck('ulid')->all();
    $before = $revisions();
    $scriptRevision = WaveScript::query()->whereKey($script->id)->value('revision');

    $ok = pl32bCall($w, 'PUT', $id, 'lines/order', ['ids' => [$z->ulid, $x->ulid, $y->ulid]])->assertOk();
    expect($order())->toBe([$z->ulid, $x->ulid, $y->ulid])->and($revisions())->toBe($before)
        ->and($ok->json('data.revision'))->toBeGreaterThan($scriptRevision);

    // the refusal carries the CURRENT order, so the web can show it again
    pl32bCall($w, 'PUT', $id, 'lines/order', ['ids' => [$z->ulid]])->assertStatus(422)->assertJsonPath('errors.0.meta.what', 'lines')
        ->assertJsonPath('errors.0.meta.current', [$z->ulid, $x->ulid, $y->ulid]);
    foreach ([[$z->ulid, $x->ulid], [$z->ulid, $x->ulid, $y->ulid, (string) Str::ulid()], [$z->ulid, $x->ulid, (string) Str::ulid()], [$z->ulid, $z->ulid, $x->ulid]] as $bad) {
        pl32bCall($w, 'PUT', $id, 'lines/order', ['ids' => $bad])->assertStatus(422)->assertJsonPath('errors.0.code', 'wave_script.order_mismatch');
    }
    expect($order())->toBe([$z->ulid, $x->ulid, $y->ulid])->and($revisions())->toBe($before);

    // a line deleted meanwhile makes the old list stale
    pl32bCall($w, 'DELETE', $id, "lines/{$y->ulid}", [], 'staff')->assertOk();
    pl32bCall($w, 'PUT', $id, 'lines/order', ['ids' => [$x->ulid, $y->ulid, $z->ulid]])->assertStatus(422)->assertJsonPath('errors.0.code', 'wave_script.order_mismatch');
});

it('R1: hooks reorder the same way: letters follow, hook revisions do not move, a stale list writes nothing', function (): void {
    $w = SF::world();
    $id = SF::api($w);
    $script = WaveScript::query()->where('ulid', $id)->firstOrFail();
    [$a, $b, $c] = SF::hooksOf($script);
    $before = WaveScriptHook::query()->orderBy('id')->pluck('revision')->all();

    $res = pl32bCall($w, 'PUT', $id, 'hooks/order', ['ids' => [$c->ulid, $a->ulid, $b->ulid]])->assertOk();
    expect(pl32bIds($res, 'data.hooks'))->toBe([$c->ulid, $a->ulid, $b->ulid])->and(array_column($res->json('data.hooks'), 'letter'))->toBe(['A', 'B', 'C'])
        ->and(WaveScriptHook::query()->orderBy('id')->pluck('revision')->all())->toBe($before);

    pl32bCall($w, 'PUT', $id, 'hooks/order', ['ids' => [$a->ulid, $b->ulid]])->assertStatus(422)->assertJsonPath('errors.0.code', 'wave_script.order_mismatch');
    expect(WaveScriptHook::query()->orderBy('position')->pluck('ulid')->all())->toBe([$c->ulid, $a->ulid, $b->ulid]);
});

it('B-roll reorder is by the complete list of ONE line: a row of another line or a short list is order_mismatch', function (): void {
    $w = SF::world();
    $id = SF::api($w);
    $script = WaveScript::query()->where('ulid', $id)->firstOrFail();
    $a = SF::line($script, 0, null, 3);
    $b = SF::line($script, 1, null, 1);
    $rows = WaveScriptBroll::query()->where('line_id', $a->id)->orderBy('position')->pluck('ulid')->all();
    $foreign = (string) WaveScriptBroll::query()->where('line_id', $b->id)->value('ulid');

    pl32bCall($w, 'PUT', $id, "lines/{$a->ulid}/broll/order", ['ids' => [$rows[2], $rows[0]]])->assertStatus(422)->assertJsonPath('errors.0.code', 'wave_script.order_mismatch');
    pl32bCall($w, 'PUT', $id, "lines/{$a->ulid}/broll/order", ['ids' => [$rows[2], $rows[0], $foreign]])->assertStatus(422);
    expect(WaveScriptBroll::query()->where('line_id', $a->id)->orderBy('position')->pluck('ulid')->all())->toBe($rows);
    pl32bCall($w, 'PUT', $id, "lines/{$a->ulid}/broll/order", ['ids' => [$rows[2], $rows[0], $rows[1]]])->assertOk();
});

it('a line copy carries the texts, the CTA flag and the B-roll fields, lands after the original and is its own block', function (): void {
    $w = SF::world();
    $id = SF::api($w);
    $script = WaveScript::query()->where('ulid', $id)->firstOrFail();
    $a = SF::line($script, 0, 'first', 2, true);
    SF::line($script, 1, 'second');
    WaveScriptBroll::query()->where('line_id', $a->id)->where('position', 0)->update(['description' => 'wide shot', 'shot_type' => 'wide']);

    $res = pl32bCall($w, 'POST', $id, "lines/{$a->ulid}/duplicate")->assertCreated();
    $lines = $res->json('data.lines');
    expect(array_column($lines, 'number'))->toBe([2, 3, 4])->and($lines[1]['texts'])->toBe(['en' => 'first'])->and($lines[1]['cta'])->toBeTrue()
        ->and($lines[1]['id'])->not->toBe($lines[0]['id'])->and($lines[1]['revision'])->toBe(1)
        ->and(array_column($lines[1]['broll'], 'description'))->toBe(['wide shot', null])->and(array_column($lines[1]['broll'], 'code'))->toBe(['3_1', '3_2'])
        ->and($lines[2]['texts'])->toBe(['en' => 'second']);
});

it('the last line can be deleted (a script needs no line); the rest close the gap', function (): void {
    $w = SF::world();
    $id = SF::api($w);
    $script = WaveScript::query()->where('ulid', $id)->firstOrFail();
    $a = SF::line($script, 0, 'a');
    $b = SF::line($script, 1, 'b');
    $c = SF::line($script, 2, 'c');

    $res = pl32bCall($w, 'DELETE', $id, "lines/{$a->ulid}")->assertOk();
    expect(array_column($res->json('data.lines'), 'number'))->toBe([2, 3])->and(pl32bIds($res))->toBe([$b->ulid, $c->ulid]);
    pl32bCall($w, 'DELETE', $id, "lines/{$b->ulid}")->assertOk();
    pl32bCall($w, 'DELETE', $id, "lines/{$c->ulid}")->assertOk()->assertJsonPath('data.lines', []);
});

it('L3: a line has an optional shot type and an optional English visual description; they save in the line block with its revision, are checked, copy on duplicate, never reach the audit as text and do not move the estimate', function (): void {
    $w = SF::world();
    $id = SF::api($w);
    $res = pl32bCall($w, 'POST', $id, 'lines')->assertCreated();
    $line = $res->json('data.lines.0');
    expect($line)->toMatchArray(['shot_type' => null, 'visual_description' => null]);
    $words = $res->json('data.estimate.words');

    $saved = pl32bCall($w, 'PUT', $id, "lines/{$line['id']}", ['shot_type' => 'creator_to_camera', 'visual_description' => 'Creator holds the phone up', 'revision' => $line['revision']])->assertOk();
    $now = $saved->json('data.lines.0');
    expect($now)->toMatchArray(['shot_type' => 'creator_to_camera', 'visual_description' => 'Creator holds the phone up', 'revision' => $line['revision'] + 1])
        ->and($saved->json('data.estimate.words'))->toBe($words);

    // checked: a code outside the ten and more than 400 characters are refused, nothing changes
    pl32bCall($w, 'PUT', $id, "lines/{$line['id']}", ['shot_type' => 'dolly_zoom', 'revision' => $now['revision']])->assertStatus(422);
    pl32bCall($w, 'PUT', $id, "lines/{$line['id']}", ['visual_description' => str_repeat('x', 401), 'revision' => $now['revision']])->assertStatus(422);
    // the stale revision with a real change is the conflict, and the line is untouched
    pl32bCall($w, 'PUT', $id, "lines/{$line['id']}", ['shot_type' => 'wide', 'revision' => $line['revision']])->assertStatus(409);
    expect(WaveScriptLine::query()->where('ulid', $line['id'])->value('shot_type'))->toBe('creator_to_camera');
    // null clears both
    pl32bCall($w, 'PUT', $id, "lines/{$line['id']}", ['shot_type' => null, 'visual_description' => null, 'revision' => $now['revision']])->assertOk();

    // duplicate carries both
    pl32bCall($w, 'PUT', $id, "lines/{$line['id']}", ['shot_type' => 'detail', 'visual_description' => 'Close on the bag', 'revision' => $now['revision'] + 1])->assertOk();
    $dup = pl32bCall($w, 'POST', $id, "lines/{$line['id']}/duplicate")->assertCreated();
    expect($dup->json('data.lines.1'))->toMatchArray(['shot_type' => 'detail', 'visual_description' => 'Close on the bag']);

    // the audit rows carry the field NAMES, never the description
    $meta = json_encode(DB::table('audit_logs')->where('action', 'wave_script_line.updated')->pluck('metadata')->all());
    expect($meta)->toContain('visual_description')->and($meta)->not->toContain('Creator holds')->and($meta)->not->toContain('Close on the bag');
});

it('S1: "the overall setting of the video" is an optional brief field (500 characters, English), saved with the brief revision, in no audit row, and it survives archive and restore', function (): void {
    $w = SF::world();
    $id = SF::api($w);
    expect(S::call($w['admin'], 'GET', SF::url($w, $id))->json('data.brief.setting'))->toBeNull();

    pl32bCall($w, 'PUT', $id, 'brief', ['setting' => str_repeat('x', 501), 'revision' => 1])->assertStatus(422);
    $saved = pl32bCall($w, 'PUT', $id, 'brief', ['setting' => 'At home in the kitchen, daylight', 'revision' => 1])->assertOk();
    expect($saved->json('data.brief.setting'))->toBe('At home in the kitchen, daylight')->and($saved->json('data.brief.revision'))->toBe(2);
    pl32bCall($w, 'PUT', $id, 'brief', ['setting' => str_repeat('y', 500), 'revision' => 2])->assertOk();
    // a stale revision with a real change conflicts and the text stays
    pl32bCall($w, 'PUT', $id, 'brief', ['setting' => 'late', 'revision' => 1])->assertStatus(409);
    pl32bCall($w, 'PUT', $id, 'brief', ['setting' => null, 'revision' => 3])->assertOk();
    pl32bCall($w, 'PUT', $id, 'brief', ['setting' => 'Kitchen counter', 'revision' => 4])->assertOk();

    $meta = json_encode(DB::table('audit_logs')->where('action', 'wave_script.updated')->pluck('metadata')->all());
    expect($meta)->toContain('setting')->and($meta)->not->toContain('Kitchen counter')->and($meta)->not->toContain('At home');

    pl32bCall($w, 'POST', $id, 'archive')->assertOk();
    S::call($w['admin'], 'POST', SF::url($w, "archived/{$id}/restore"))->assertOk();
    expect(S::call($w['admin'], 'GET', SF::url($w, $id))->json('data.brief.setting'))->toBe('Kitchen counter');
});

it('FIX1: a save addressed to a deleted line id never touches another line, even one that moved up into its place; the same for its B-roll rows and for a hook', function (): void {
    $w = SF::world();
    $id = SF::api($w);
    $script = WaveScript::query()->where('ulid', $id)->firstOrFail();
    $a = SF::line($script, 0, 'first', 1);
    $b = SF::line($script, 1, 'second');
    $c = SF::line($script, 2, 'third');
    $rowA = (string) WaveScriptBroll::query()->where('line_id', $a->id)->value('ulid');

    // the other tab deletes line a; b moves up to position 0 (number 2)
    pl32bCall($w, 'DELETE', $id, "lines/{$a->ulid}", [], 'staff')->assertOk();
    $before = [$b->refresh()->only(['revision', 'shot_type', 'visual_description', 'cta', 'position']), $c->refresh()->only(['revision', 'position']), WaveScriptLineText::query()->orderBy('id')->pluck('body', 'language')->all()];

    // saves, a duplicate, a B-roll add, a row save, an image reservation and a delete, all by the DELETED id
    pl32bCall($w, 'PUT', $id, "lines/{$a->ulid}", ['texts' => ['en' => 'late words'], 'cta' => true, 'shot_type' => 'wide', 'revision' => 1])->assertNotFound()->assertJsonPath('errors.0.code', 'wave_script_line.deleted');
    pl32bCall($w, 'PUT', $id, "lines/{$a->ulid}", ['broll' => [$rowA => ['description' => 'late']], 'revision' => 1])->assertNotFound();
    pl32bCall($w, 'POST', $id, "lines/{$a->ulid}/duplicate")->assertNotFound();
    pl32bCall($w, 'POST', $id, "lines/{$a->ulid}/broll")->assertNotFound();
    pl32bCall($w, 'DELETE', $id, "lines/{$a->ulid}/broll/{$rowA}")->assertNotFound();
    pl32bCall($w, 'DELETE', $id, "lines/{$a->ulid}")->assertNotFound();
    expect(S::call($w['staff'], 'POST', SF::url($w, "{$id}/images/uploads/init"), ['block' => 'line', 'id' => $a->ulid, 'mime_type' => 'image/png', 'declared_bytes' => 10, 'original_filename' => 'x.png'])->status())->toBe(404);

    // nothing of that reached a line that is still there
    $after = [$b->refresh()->only(['revision', 'shot_type', 'visual_description', 'cta', 'position']), $c->refresh()->only(['revision', 'position']), WaveScriptLineText::query()->orderBy('id')->pluck('body', 'language')->all()];
    expect($after)->toBe($before)->and(WaveScriptLine::query()->count())->toBe(2)->and(WaveScriptBroll::query()->count())->toBe(0);
    expect(WaveScriptLineText::query()->where('body', 'late words')->exists())->toBeFalse();

    // a hook: delete hook B, then save to B's id: 404 with its own code, hook C (which is now B) is untouched
    $hooks = SF::hooksOf($script);
    pl32bCall($w, 'DELETE', $id, "hooks/{$hooks[1]->ulid}", [], 'staff')->assertOk();
    $c1 = $hooks[2]->refresh()->only(['revision', 'visual_description', 'position']);
    pl32bCall($w, 'PUT', $id, "hooks/{$hooks[1]->ulid}", ['visual_description' => 'late', 'revision' => 1])->assertNotFound()->assertJsonPath('errors.0.code', 'wave_script_hook.deleted');
    pl32bCall($w, 'DELETE', $id, "hooks/{$hooks[1]->ulid}")->assertNotFound();
    expect($hooks[2]->refresh()->only(['revision', 'visual_description', 'position']))->toBe($c1);
});
