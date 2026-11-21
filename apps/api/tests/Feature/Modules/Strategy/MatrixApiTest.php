<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Brands\Enums\BrandStatus;
use App\Modules\Strategy\Enums\AngleOrigin;
use App\Modules\Strategy\Models\WaveMatrixCell;
use App\Modules\Strategy\Models\WavePersona;
use App\Modules\Strategy\Models\WavePersonaAngle;
use App\Modules\Strategy\Models\WaveUsp;
use App\Modules\Waves\Models\Wave;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\StrategyFixtures as S;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * PL-2.2 — the messaging matrix: the market load (constant queries), the cell write (one and many), clear, and the
 * sync with the persona angles. The sync table of the plan (section 1.7, rows 1 to 24) is covered by the tests named
 * "X-01" to "X-24" below, one per row. Gates: flags (StrategyFlagGatesTest route table), roles, tenancy, same wave and
 * market, atomic, validation, audit, queries, progress, read-only synced angles, delete cascades, copy.
 */

/**
 * The world plus three USPs (A, B, C) and personas SK1, SK2 (market SK) and RO1 (market RO), made through the API.
 *
 * @return array<string, mixed>
 */
function mx(): array
{
    $w = S::world();
    foreach (['A', 'B', 'C'] as $t) {
        $w['u'.$t] = S::call($w['admin'], 'POST', S::u($w, 'usps'), ['title' => "USP {$t}"])->assertCreated()->json('data.id');
    }
    foreach ([['SK', 'p1'], ['SK', 'p2'], ['RO', 'r1']] as [$market, $key]) {
        $w[$key] = S::call($w['admin'], 'POST', S::u($w, 'personas'), ['market' => S::$mk[$market], 'title' => "Persona {$key}", 'name' => $key])->assertCreated()->json('data.id');
    }

    return $w;
}

/**
 * @param  array<string, mixed>  $cell
 */
/** @return TestResponse<Response> */
function mxPut(array $w, array $cell, string $who = 'admin', string $market = 'SK'): TestResponse
{
    return S::call($w[$who], 'PUT', S::u($w, 'matrix/cells'), ['market' => S::$mk[$market] ?? S::NO_MARKET, 'cells' => [$cell]]);
}

function mxCell(string $persona, string $usp): WaveMatrixCell
{
    return mxCellOrNull($persona, $usp) ?? throw new RuntimeException('no cell on this pair');
}

function mxCellOrNull(string $persona, string $usp): ?WaveMatrixCell
{
    return WaveMatrixCell::query()
        ->where('persona_id', WavePersona::query()->where('ulid', $persona)->value('id'))
        ->where('usp_id', WaveUsp::query()->where('ulid', $usp)->value('id'))->first();
}

function mxAngle(string $persona, string $usp): WavePersonaAngle
{
    return mxAngleOrNull($persona, $usp) ?? throw new RuntimeException('no angle on this pair');
}

function mxAngleOrNull(string $persona, string $usp): ?WavePersonaAngle
{
    return WavePersonaAngle::query()
        ->where('persona_id', WavePersona::query()->where('ulid', $persona)->value('id'))
        ->where('usp_id', WaveUsp::query()->where('ulid', $usp)->value('id'))->first();
}

/** A hand-written angle through the persona endpoint. */
function mxManual(array $w, string $persona, string $usp, string $text = 'Written by hand'): string
{
    return S::call($w['admin'], 'POST', S::u($w, "personas/{$persona}/angles"), ['usp' => $usp, 'explanation' => $text])->assertCreated()->json('data.id');
}

/**
 * The invariant of plan 1.3: every Priority/Support cell with a message line has exactly ONE angle on its pair (matrix
 * or manual); no matrix angle exists without such a cell; the unique key is the rest.
 */
function mxInvariant(): void
{
    foreach (WaveMatrixCell::query()->get() as $cell) {
        $angles = WavePersonaAngle::query()->where('persona_id', $cell->persona_id)->where('usp_id', $cell->usp_id)->get();
        if ($cell->level->feedsAngle() && $cell->message_line !== null) {
            expect($angles)->toHaveCount(1);
            if ($angles->firstOrFail()->origin === AngleOrigin::Matrix) {
                expect($angles->firstOrFail()->explanation)->toBe($cell->message_line);
            }
        } else {
            expect($angles->where('origin', AngleOrigin::Matrix))->toHaveCount(0);
        }
    }
    foreach (WavePersonaAngle::query()->where('origin', AngleOrigin::Matrix->value)->get() as $angle) {
        $cell = WaveMatrixCell::query()->where('persona_id', $angle->persona_id)->where('usp_id', $angle->usp_id)->firstOrFail();
        expect($cell->level->feedsAngle())->toBeTrue();
    }
}

// --- the sync table (plan 1.7), one test per row --------------------------------------------------------------------

it('X-01: a Priority cell with a message line on an empty pair creates a synced angle', function (): void {
    $w = mx();
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'level' => 'priority', 'rationale' => 'Why', 'message_line' => 'Lead line'])->assertOk()
        ->assertJsonPath('data.cells.0.level', 'priority')->assertJsonPath('data.cells.0.has_manual_angle', false);

    $angle = mxAngle($w['p1'], $w['uA']);
    expect($angle->origin)->toBe(AngleOrigin::Matrix)->and($angle->explanation)->toBe('Lead line');
    mxInvariant();
});

it('X-02: the same with a hand-written angle there leaves it exactly as it is and makes no second angle', function (): void {
    $w = mx();
    mxManual($w, $w['p1'], $w['uA'], 'Mine');
    $before = mxAngle($w['p1'], $w['uA'])->only(['id', 'ulid', 'explanation', 'origin', 'updated_at']);

    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'level' => 'support', 'message_line' => 'Lead line'])->assertOk()
        ->assertJsonPath('data.cells.0.has_manual_angle', true);

    expect(WavePersonaAngle::query()->count())->toBe(1)
        ->and(mxAngle($w['p1'], $w['uA'])->only(['id', 'ulid', 'explanation', 'origin', 'updated_at']))->toEqual($before)
        ->and(mxAngle($w['p1'], $w['uA'])->explanation)->toBe('Mine');
    mxInvariant();
});

it('X-03: Priority to Support and back, message unchanged, leaves the synced angle alone', function (): void {
    $w = mx();
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'level' => 'priority', 'message_line' => 'Lead line'])->assertOk();
    $before = mxAngle($w['p1'], $w['uA'])->only(['id', 'explanation', 'updated_at']);

    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'level' => 'support'])->assertOk()->assertJsonPath('data.cells.0.level', 'support');
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'level' => 'priority'])->assertOk();

    expect(mxAngle($w['p1'], $w['uA'])->only(['id', 'explanation', 'updated_at']))->toEqual($before);
    mxInvariant();
});

it('X-04: changing only the message line updates the synced angle text', function (): void {
    $w = mx();
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'level' => 'priority', 'message_line' => 'One'])->assertOk();
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'message_line' => 'Two'])->assertOk();

    expect(mxAngle($w['p1'], $w['uA'])->explanation)->toBe('Two')->and(mxCell($w['p1'], $w['uA'])->rationale)->toBeNull();
    mxInvariant();
});

it('X-05: changing only the message line with a hand-written angle there leaves that angle alone', function (): void {
    $w = mx();
    mxManual($w, $w['p1'], $w['uA'], 'Mine');
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'level' => 'priority', 'message_line' => 'One'])->assertOk();
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'message_line' => 'Two'])->assertOk();

    expect(mxAngle($w['p1'], $w['uA'])->explanation)->toBe('Mine')->and(mxAngle($w['p1'], $w['uA'])->origin)->toBe(AngleOrigin::Manual)
        ->and(mxCell($w['p1'], $w['uA'])->message_line)->toBe('Two');
    mxInvariant();
});

it('X-06: setting a Priority or Support cell to Low removes the synced angle and keeps the cell texts', function (): void {
    $w = mx();
    foreach (['priority', 'support'] as $level) {
        mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'level' => $level, 'rationale' => 'R', 'message_line' => 'M'])->assertOk();
        expect(mxAngleOrNull($w['p1'], $w['uA']))->not->toBeNull();

        mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'level' => 'low'])->assertOk();
        expect(mxAngleOrNull($w['p1'], $w['uA']))->toBeNull()
            ->and(mxCell($w['p1'], $w['uA'])->message_line)->toBe('M')->and(mxCell($w['p1'], $w['uA'])->rationale)->toBe('R');
    }
    mxInvariant();
});

it('X-07: setting a cell to Low with a hand-written angle there leaves that angle alone', function (): void {
    $w = mx();
    mxManual($w, $w['p1'], $w['uA'], 'Mine');
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'level' => 'priority', 'message_line' => 'M'])->assertOk();
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'level' => 'low'])->assertOk();

    expect(WavePersonaAngle::query()->count())->toBe(1)->and(mxAngle($w['p1'], $w['uA'])->explanation)->toBe('Mine');
    mxInvariant();
});

it('X-08: clearing a cell deletes the row and the synced angle', function (): void {
    $w = mx();
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'level' => 'priority', 'message_line' => 'M'])->assertOk();
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'clear' => true])->assertOk()->assertJsonPath('data.cells.0.level', null);

    expect(mxCellOrNull($w['p1'], $w['uA']))->toBeNull()->and(mxAngleOrNull($w['p1'], $w['uA']))->toBeNull();
    // A Low cell is cleared the same way (no angle to remove).
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uB'], 'level' => 'low'])->assertOk();
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uB'], 'clear' => true])->assertOk();
    expect(mxCellOrNull($w['p1'], $w['uB']))->toBeNull();
    mxInvariant();
});

it('X-09: clearing a cell with a hand-written angle there leaves that angle alone', function (): void {
    $w = mx();
    mxManual($w, $w['p1'], $w['uA'], 'Mine');
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'level' => 'support', 'message_line' => 'M'])->assertOk();
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'clear' => true])->assertOk();

    expect(mxCellOrNull($w['p1'], $w['uA']))->toBeNull()->and(mxAngle($w['p1'], $w['uA'])->explanation)->toBe('Mine');
});

it('X-10: a Low cell on an empty pair makes no angle, and a Low cell needs no message line', function (): void {
    $w = mx();
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'level' => 'low'])->assertOk();

    expect(mxCell($w['p1'], $w['uA'])->level->value)->toBe('low')->and(WavePersonaAngle::query()->count())->toBe(0);
});

it('X-11: a Low cell with a hand-written angle there leaves that angle alone', function (): void {
    $w = mx();
    mxManual($w, $w['p1'], $w['uA'], 'Mine');
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'level' => 'low', 'rationale' => 'Not it'])->assertOk();

    expect(WavePersonaAngle::query()->count())->toBe(1)->and(mxAngle($w['p1'], $w['uA'])->explanation)->toBe('Mine');
});

it('X-12: Low to Priority with a message line creates the synced angle', function (): void {
    $w = mx();
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'level' => 'low', 'message_line' => 'Kept text'])->assertOk();
    expect(mxAngleOrNull($w['p1'], $w['uA']))->toBeNull();

    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'level' => 'priority'])->assertOk();   // the stored message line is enough

    expect(mxAngle($w['p1'], $w['uA'])->explanation)->toBe('Kept text')->and(mxAngle($w['p1'], $w['uA'])->origin)->toBe(AngleOrigin::Matrix);
    mxInvariant();
});

it('X-13: Low to Priority with a hand-written angle there leaves that angle alone', function (): void {
    $w = mx();
    mxManual($w, $w['p1'], $w['uA'], 'Mine');
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'level' => 'low', 'message_line' => 'T'])->assertOk();
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'level' => 'priority'])->assertOk();

    expect(WavePersonaAngle::query()->count())->toBe(1)->and(mxAngle($w['p1'], $w['uA'])->explanation)->toBe('Mine');
    mxInvariant();
});

it('X-14: a Priority or Support cell without a message line is refused (422) and nothing is written; Low is fine', function (): void {
    $w = mx();
    foreach (['priority', 'support'] as $level) {
        mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'level' => $level, 'rationale' => 'R'])
            ->assertUnprocessable()->assertJsonPath('errors.0.meta.field', 'cells.0.message_line');
    }
    expect(WaveMatrixCell::query()->count())->toBe(0)->and(AuditLog::query()->where('action', 'wave_matrix_cell.saved')->count())->toBe(0);

    // An existing cell cannot lose its message line while Priority/Support, nor become Priority without one.
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'level' => 'priority', 'message_line' => 'M'])->assertOk();
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'message_line' => null])->assertUnprocessable();
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uB'], 'level' => 'low'])->assertOk();
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uB'], 'level' => 'support'])->assertUnprocessable();
    expect(mxCell($w['p1'], $w['uA'])->message_line)->toBe('M')->and(mxCell($w['p1'], $w['uB'])->level->value)->toBe('low');
    mxInvariant();
});

it('X-15: removing the hand-written angle while the cell is Priority or Support creates the synced angle in the same request', function (): void {
    $w = mx();
    $manual = mxManual($w, $w['p1'], $w['uA'], 'Mine');
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'level' => 'priority', 'message_line' => 'From the cell'])->assertOk();

    S::call($w['admin'], 'DELETE', S::u($w, "personas/{$w['p1']}/angles/{$manual}"))->assertNoContent();

    $angle = mxAngle($w['p1'], $w['uA']);
    expect($angle->origin)->toBe(AngleOrigin::Matrix)->and($angle->explanation)->toBe('From the cell');
    expect(AuditLog::query()->where('action', 'wave_persona_angle.removed')->firstOrFail()->metadata)->toMatchArray(['matrix_angle_created' => true]);
    mxInvariant();
});

it('X-16: removing the hand-written angle while the cell is Low or not set leaves nothing in its place', function (): void {
    $w = mx();
    $a = mxManual($w, $w['p1'], $w['uA']);
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'level' => 'low'])->assertOk();
    S::call($w['admin'], 'DELETE', S::u($w, "personas/{$w['p1']}/angles/{$a}"))->assertNoContent();
    expect(mxAngleOrNull($w['p1'], $w['uA']))->toBeNull();

    $b = mxManual($w, $w['p2'], $w['uA']);   // no cell at all
    S::call($w['admin'], 'DELETE', S::u($w, "personas/{$w['p2']}/angles/{$b}"))->assertNoContent();
    expect(mxAngleOrNull($w['p2'], $w['uA']))->toBeNull()
        ->and(AuditLog::query()->where('action', 'wave_persona_angle.removed')->get()->every(fn ($r) => ($r->metadata ?? [])['matrix_angle_created'] === false))->toBeTrue();
});

it('X-17: a hand-written angle cannot be added for a USP that already has a synced angle (422 angle.already_linked)', function (): void {
    $w = mx();
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'level' => 'priority', 'message_line' => 'M'])->assertOk();

    S::call($w['admin'], 'POST', S::u($w, "personas/{$w['p1']}/angles"), ['usp' => $w['uA'], 'explanation' => 'x'])
        ->assertUnprocessable()->assertJsonPath('errors.0.code', 'angle.already_linked');
    expect(WavePersonaAngle::query()->count())->toBe(1);
});

it('X-18: a synced angle cannot be edited or removed on the persona endpoints (422 angle.synced_read_only), and the list says read-only', function (): void {
    $w = mx();
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'level' => 'priority', 'message_line' => 'M'])->assertOk();
    $angle = mxAngle($w['p1'], $w['uA']);

    S::call($w['admin'], 'PATCH', S::u($w, "personas/{$w['p1']}/angles/{$angle->ulid}"), ['explanation' => 'x'])
        ->assertUnprocessable()->assertJsonPath('errors.0.code', 'angle.synced_read_only');
    S::call($w['admin'], 'DELETE', S::u($w, "personas/{$w['p1']}/angles/{$angle->ulid}"))
        ->assertUnprocessable()->assertJsonPath('errors.0.code', 'angle.synced_read_only');
    expect($angle->refresh()->explanation)->toBe('M');

    $row = collect((array) S::call($w['admin'], 'GET', S::u($w, "personas?market={$w['mk']['SK']}"))->assertOk()->json('data'))->firstWhere('id', $w['p1']);
    expect($row['attributes']['angles'][0]['attributes'])->toMatchArray(['origin' => 'matrix', 'read_only' => true, 'market' => $w['mk']['SK']])
        ->and($row['attributes']['matrix_cells'])->toBe([['usp' => $w['uA'], 'level' => 'priority', 'message_line' => 'M']]);
});

it('X-19: deleting a USP removes its cells, its synced angles and its hand-written angles after the confirmation lists them', function (): void {
    $w = mx();
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'level' => 'priority', 'message_line' => 'M'])->assertOk();
    mxPut($w, ['persona' => $w['p2'], 'usp' => $w['uA'], 'level' => 'low'])->assertOk();
    mxManual($w, $w['r1'], $w['uA']);

    $res = S::call($w['admin'], 'DELETE', S::u($w, "usps/{$w['uA']}"))->assertStatus(409);
    expect($res->json('errors.0.meta.count'))->toBe(3)
        ->and(collect((array) $res->json('errors.0.meta.affected'))->pluck('kind')->sort()->values()->all())->toBe(['angle', 'cell', 'cell']);

    S::call($w['admin'], 'DELETE', S::u($w, "usps/{$w['uA']}?confirm_count=2"))->assertStatus(409);   // stale
    S::call($w['admin'], 'DELETE', S::u($w, "usps/{$w['uA']}?confirm_count=3"))->assertNoContent();

    expect(WaveMatrixCell::query()->count())->toBe(0)->and(WavePersonaAngle::query()->count())->toBe(0);
    expect(AuditLog::query()->where('action', 'wave_usp.deleted')->firstOrFail()->metadata)->toMatchArray(['angles_removed' => 2, 'cells_removed' => 2]);
});

it('X-20: deleting a persona removes its cells and synced angles; other personas keep theirs', function (): void {
    $w = mx();
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'level' => 'priority', 'message_line' => 'M'])->assertOk();
    mxPut($w, ['persona' => $w['p2'], 'usp' => $w['uA'], 'level' => 'support', 'message_line' => 'N'])->assertOk();

    S::call($w['staff'], 'DELETE', S::u($w, "personas/{$w['p1']}"))->assertNoContent();

    expect(WaveMatrixCell::query()->count())->toBe(1)->and(mxCell($w['p2'], $w['uA']))->not->toBeNull()->and(WavePersonaAngle::query()->count())->toBe(1);
    expect(AuditLog::query()->where('action', 'wave_persona.deleted')->firstOrFail()->metadata)->toMatchArray(['angles_removed' => 1, 'cells_removed' => 1]);
    mxInvariant();
});

it('X-21: copying personas to another market copies hand-written angles only; no cell and no synced angle; the number skipped is reported', function (): void {
    $w = mx();
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'level' => 'priority', 'message_line' => 'Synced'])->assertOk();   // synced on p1
    mxManual($w, $w['p1'], $w['uB'], 'Mine');                                                                                 // manual on p1
    mxPut($w, ['persona' => $w['p2'], 'usp' => $w['uA'], 'level' => 'support', 'message_line' => 'Synced 2'])->assertOk();    // synced on p2

    $res = S::call($w['admin'], 'POST', S::u($w, 'personas/copy'), ['from_market' => $w['mk']['SK'], 'to_market' => $w['mk']['CZ']])->assertCreated();

    expect($res->json('meta.synced_angles_skipped'))->toBe(2);
    $copies = WavePersona::query()->where('market_id', $w['mkid']['CZ'])->get();
    expect($copies)->toHaveCount(2)
        ->and(WaveMatrixCell::query()->where('market_id', $w['mkid']['CZ'])->count())->toBe(0)
        ->and(WavePersonaAngle::query()->whereIn('persona_id', $copies->modelKeys())->count())->toBe(1)
        ->and(WavePersonaAngle::query()->whereIn('persona_id', $copies->modelKeys())->firstOrFail()->origin)->toBe(AngleOrigin::Manual);
    expect(AuditLog::query()->where('action', 'wave_persona.copied')->firstOrFail()->metadata)->toMatchArray(['synced_angles_skipped' => 2]);
    mxInvariant();
});

it('X-22: a new USP and a new persona add no cell: the new row and column are "Not set"', function (): void {
    $w = mx();
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'level' => 'priority', 'message_line' => 'M'])->assertOk();
    $count = WaveMatrixCell::query()->count();

    S::call($w['admin'], 'POST', S::u($w, 'usps'), ['title' => 'New USP'])->assertCreated();
    S::call($w['admin'], 'POST', S::u($w, 'personas'), ['market' => $w['mk']['SK'], 'title' => 'T', 'name' => 'N'])->assertCreated();

    expect(WaveMatrixCell::query()->count())->toBe($count);
    $m = S::call($w['admin'], 'GET', S::u($w, "matrix?market={$w['mk']['SK']}"))->assertOk()->json('data.attributes');
    expect($m['usps'])->toHaveCount(4)->and($m['personas'])->toHaveCount(3)->and($m['cells'])->toHaveCount(1);
});

it('X-23: an archived brand refuses every cell write (422 wave.brand_archived) and still loads the matrix', function (): void {
    $w = mx();
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'level' => 'priority', 'message_line' => 'M'])->assertOk();
    $w['brand']->forceFill(['status' => BrandStatus::Archived])->save();

    S::call($w['admin'], 'GET', S::u($w, "matrix?market={$w['mk']['SK']}"))->assertOk();
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'level' => 'low'])->assertUnprocessable()->assertJsonPath('errors.0.code', 'wave.brand_archived');
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'clear' => true])->assertUnprocessable();
    expect(mxCell($w['p1'], $w['uA'])->level->value)->toBe('priority');
});

it('X-24: the same request twice writes nothing the second time (rows, updated_at and audit unchanged)', function (): void {
    $w = mx();
    $cell = ['persona' => $w['p1'], 'usp' => $w['uA'], 'level' => 'priority', 'rationale' => 'R', 'message_line' => 'M'];
    mxPut($w, $cell)->assertOk();
    $c1 = mxCell($w['p1'], $w['uA'])->only(['id', 'updated_at']);
    $a1 = mxAngle($w['p1'], $w['uA'])->only(['id', 'updated_at']);
    $audits = AuditLog::query()->count();
    $this->travel(5)->minutes();

    mxPut($w, $cell)->assertOk()->assertJsonPath('data.cells.0.level', 'priority');

    expect(mxCell($w['p1'], $w['uA'])->only(['id', 'updated_at']))->toEqual($c1)
        ->and(mxAngle($w['p1'], $w['uA'])->only(['id', 'updated_at']))->toEqual($a1)
        ->and(AuditLog::query()->count())->toBe($audits)
        ->and(WavePersonaAngle::query()->count())->toBe(1);
    // Clearing an already empty cell is also a no-op.
    mxPut($w, ['persona' => $w['p2'], 'usp' => $w['uC'], 'clear' => true])->assertOk();
    expect(AuditLog::query()->count())->toBe($audits);
});

// --- loading the matrix ---------------------------------------------------------------------------------------------

it('M-01: GET matrix returns the USPs in order, the market\'s personas in slot order, the set cells and the manual-angle flags', function (): void {
    $w = mx();
    mxManual($w, $w['p2'], $w['uB'], 'Mine');
    mxPut($w, ['persona' => $w['p2'], 'usp' => $w['uB'], 'level' => 'priority', 'rationale' => 'R', 'message_line' => 'M'])->assertOk();
    mxPut($w, ['persona' => $w['r1'], 'usp' => $w['uA'], 'level' => 'low'], market: 'RO')->assertOk();

    $m = S::call($w['staff'], 'GET', S::u($w, "matrix?market={$w['mk']['SK']}"))->assertOk()->json('data');

    expect($m['type'])->toBe('wave_matrix')
        ->and(array_column($m['attributes']['usps'], 'title'))->toBe(['USP A', 'USP B', 'USP C'])
        ->and(array_column($m['attributes']['usps'], 'color_key'))->toBe(['violet', 'green', 'blue'])
        ->and(array_column($m['attributes']['personas'], 'id'))->toBe([$w['p1'], $w['p2']])
        ->and($m['attributes']['cells'])->toHaveCount(1)
        ->and($m['attributes']['cells'][0])->toMatchArray(['persona' => $w['p2'], 'usp' => $w['uB'], 'level' => 'priority', 'rationale' => 'R', 'message_line' => 'M', 'has_manual_angle' => true, 'source' => 'manual']);
    // The RO matrix is separate.
    $ro = S::call($w['admin'], 'GET', S::u($w, "matrix?market={$w['mk']['RO']}"))->assertOk()->json('data.attributes');
    expect($ro['cells'])->toHaveCount(1)->and(array_column($ro['personas'], 'id'))->toBe([$w['r1']]);
});

it('M-02: a market that is not the wave\'s, a missing market and a malformed market are refused', function (): void {
    $w = mx();
    S::call($w['admin'], 'GET', S::u($w, 'matrix?market=DE'))->assertUnprocessable()->assertJsonPath('errors.0.code', 'persona.market_not_in_wave');
    S::call($w['admin'], 'GET', S::u($w, 'matrix'))->assertUnprocessable()->assertJsonPath('errors.0.code', 'persona.market_not_in_wave');
    S::call($w['admin'], 'GET', S::u($w, 'matrix?market=sk'))->assertUnprocessable();
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'level' => 'low'], market: 'DE')->assertUnprocessable()->assertJsonPath('errors.0.code', 'persona.market_not_in_wave');
});

it('M-03: one request loads a market in a constant number of queries, for 1 persona and for 8 personas and 6 USPs', function (): void {
    $w = S::world();
    $count = function (string $market) use ($w): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        S::call($w['admin'], 'GET', S::u($w, "matrix?market={$w['mk'][$market]}"))->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };
    $w['one'] = S::call($w['admin'], 'POST', S::u($w, 'personas'), ['market' => $w['mk']['RO'], 'title' => 'T', 'name' => 'N'])->assertCreated()->json('data.id');
    S::call($w['admin'], 'POST', S::u($w, 'usps'), ['title' => 'U'])->assertCreated();
    $small = $count('RO');

    for ($i = 0; $i < 8; $i++) {
        $p = S::call($w['admin'], 'POST', S::u($w, 'personas'), ['market' => $w['mk']['SK'], 'title' => "T{$i}", 'name' => 'N'])->assertCreated()->json('data.id');
        for ($u = 1; $u < 6; $u++) {
            if ($i === 0) {
                S::call($w['admin'], 'POST', S::u($w, 'usps'), ['title' => "U{$u}"])->assertCreated();
            }
        }
        foreach (WaveUsp::query()->get() as $usp) {
            mxPut($w, ['persona' => $p, 'usp' => $usp->ulid, 'level' => 'low'])->assertOk();
        }
    }
    expect(WaveMatrixCell::query()->where('market_id', $w['mkid']['SK'])->count())->toBe(48);
    $big = $count('SK');

    expect($big)->toBe($small);
});

it('M-04: the summary carries the wave-level progress across markets (Low counts as set; a market without personas adds nothing)', function (): void {
    $w = mx();   // 3 USPs; SK 2 personas = 6 cells, RO 1 persona = 3, CZ none
    $read = fn (): array => S::call($w['admin'], 'GET', S::u($w))->assertOk()->json('data.attributes.matrix');
    expect($read())->toMatchArray(['cells_set' => 0, 'cells_total' => 9]);

    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'level' => 'low'])->assertOk();
    mxPut($w, ['persona' => $w['p2'], 'usp' => $w['uB'], 'level' => 'priority', 'message_line' => 'M'])->assertOk();
    mxPut($w, ['persona' => $w['r1'], 'usp' => $w['uC'], 'level' => 'support', 'message_line' => 'M'], market: 'RO')->assertOk();

    $m = $read();
    expect($m['cells_set'])->toBe(3)->and($m['cells_total'])->toBe(9)
        ->and($m['by_market'])->toBe([['market' => $w['mk']['SK'], 'set' => 2, 'total' => 6], ['market' => $w['mk']['RO'], 'set' => 1, 'total' => 3], ['market' => $w['mk']['CZ'], 'set' => 0, 'total' => 0]]);

    // The write response carries the same numbers.
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uB'], 'level' => 'low'])->assertOk()->assertJsonPath('data.progress.cells_set', 4);
});

// --- the write ------------------------------------------------------------------------------------------------------

it('W-01: a list of cells is written in one request: 48 cells, one audit row with counts and levels and no text', function (): void {
    $w = S::world();
    foreach (range(1, 6) as $i) {
        S::call($w['admin'], 'POST', S::u($w, 'usps'), ['title' => "U{$i}"])->assertCreated();
    }
    $personas = [];
    foreach (range(1, 8) as $i) {
        $personas[] = S::call($w['admin'], 'POST', S::u($w, 'personas'), ['market' => $w['mk']['SK'], 'title' => "T{$i}", 'name' => 'N'])->assertCreated()->json('data.id');
    }
    $cells = [];
    foreach ($personas as $p) {
        foreach (WaveUsp::query()->orderBy('position')->get() as $k => $usp) {
            $cells[] = ['persona' => $p, 'usp' => $usp->ulid, 'level' => $k % 2 === 0 ? 'priority' : 'low', 'rationale' => 'SECRET-RATIONALE', 'message_line' => 'SECRET-MESSAGE'];
        }
    }
    expect($cells)->toHaveCount(48);

    $res = S::call($w['admin'], 'PUT', S::u($w, 'matrix/cells'), ['market' => $w['mk']['SK'], 'cells' => $cells])->assertOk();

    expect($res->json('data.cells'))->toHaveCount(48)->and($res->json('data.progress.cells_set'))->toBe(48)
        ->and(WaveMatrixCell::query()->count())->toBe(48)->and(WavePersonaAngle::query()->count())->toBe(24);
    $rows = AuditLog::query()->where('action', 'wave_matrix_cell.saved')->get();
    expect($rows)->toHaveCount(1)
        ->and(($rows[0]->metadata ?? []))->toMatchArray(['market' => $w['mk']['SK'], 'cells_created' => 48, 'angles_created' => 24, 'levels' => ['priority' => 24, 'low' => 24]])
        ->and(json_encode(($rows[0]->metadata ?? [])))->not->toContain('SECRET');
    mxInvariant();
});

it('W-02: a list is all or nothing: an unknown USP, a missing message line or a persona of another market writes nothing', function (): void {
    $w = mx();
    $ok = ['persona' => $w['p1'], 'usp' => $w['uA'], 'level' => 'priority', 'message_line' => 'M'];
    $put = fn (array $cells) => S::call($w['admin'], 'PUT', S::u($w, 'matrix/cells'), ['market' => $w['mk']['SK'], 'cells' => $cells]);

    $put([$ok, ['persona' => $w['p1'], 'usp' => '01HZZZZZZZZZZZZZZZZZZZZZZZ', 'level' => 'low']])->assertNotFound();
    $put([$ok, ['persona' => $w['p2'], 'usp' => $w['uB'], 'level' => 'support']])->assertUnprocessable();
    $put([$ok, ['persona' => $w['r1'], 'usp' => $w['uB'], 'level' => 'low']])->assertNotFound();   // r1 is an RO persona

    expect(WaveMatrixCell::query()->count())->toBe(0)->and(WavePersonaAngle::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'wave_matrix_cell.saved')->count())->toBe(0);
});

it('W-03: per field last write wins: two writers of different fields both keep their change; the same field: the later wins', function (): void {
    $w = mx();
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'level' => 'support', 'rationale' => 'R0', 'message_line' => 'M0'])->assertOk();
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'rationale' => 'R1'])->assertOk();     // writer 1: rationale only
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'message_line' => 'M1'])->assertOk();   // writer 2: message line only

    $c = mxCell($w['p1'], $w['uA']);
    expect($c->rationale)->toBe('R1')->and($c->message_line)->toBe('M1')->and($c->level->value)->toBe('support');
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'rationale' => 'R2'])->assertOk();
    expect(mxCell($w['p1'], $w['uA'])->rationale)->toBe('R2');
    // A null rationale clears the field and nothing else.
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'rationale' => null])->assertOk();
    expect(mxCell($w['p1'], $w['uA'])->rationale)->toBeNull()->and(mxCell($w['p1'], $w['uA'])->message_line)->toBe('M1');
});

it('W-04: validation: levels, the 140 limit, a first write needs a level, 1 to 48 cells, duplicates, clear carries no other field', function (): void {
    $w = mx();
    $base = ['persona' => $w['p1'], 'usp' => $w['uA']];
    $put = fn (array $body) => S::call($w['admin'], 'PUT', S::u($w, 'matrix/cells'), $body + ['market' => $w['mk']['SK']]);

    mxPut($w, $base + ['level' => 'urgent'])->assertUnprocessable();
    mxPut($w, $base + ['rationale' => 'only text'])->assertUnprocessable()->assertJsonPath('errors.0.meta.field', 'cells.0.level');
    mxPut($w, $base + ['level' => 'low', 'rationale' => str_repeat('x', 141)])->assertUnprocessable();
    mxPut($w, $base + ['level' => 'priority', 'message_line' => str_repeat('x', 141)])->assertUnprocessable();
    mxPut($w, $base + ['level' => 'low', 'rationale' => str_repeat('é', 140)])->assertOk();   // 140 characters (multibyte)
    mxPut($w, $base + ['clear' => true, 'level' => 'low'])->assertUnprocessable();
    $put(['cells' => []])->assertUnprocessable();
    $put(['cells' => array_fill(0, 49, $base + ['level' => 'low'])])->assertUnprocessable();
    $put(['cells' => [$base + ['level' => 'low'], $base + ['level' => 'support', 'message_line' => 'M']]])->assertUnprocessable();
    $put(['cells' => [['persona' => 'short', 'usp' => $w['uA'], 'level' => 'low']]])->assertUnprocessable();
    expect(WaveMatrixCell::query()->count())->toBe(1);
});

it('W-05: text is cleaned and stored as plain text (a hostile string stays a string); an empty line is null', function (): void {
    $w = mx();
    $hostile = '<img src=x onerror=alert(1)>';
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'level' => 'priority', 'rationale' => "  {$hostile}\u{0007} ", 'message_line' => $hostile])->assertOk();
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uB'], 'level' => 'low', 'rationale' => '   '])->assertOk();

    expect(mxCell($w['p1'], $w['uA'])->rationale)->toBe($hostile)->and(mxAngle($w['p1'], $w['uA'])->explanation)->toBe($hostile)
        ->and(mxCell($w['p1'], $w['uB'])->rationale)->toBeNull();
});

it('W-06: audit: one row per write, ids and counts and levels and field names only, none when nothing changed', function (): void {
    $w = mx();
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'level' => 'support', 'rationale' => 'SECRET-R', 'message_line' => 'SECRET-M'])->assertOk();
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'message_line' => 'SECRET-M2'])->assertOk();

    $rows = AuditLog::query()->where('action', 'wave_matrix_cell.saved')->orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and(($rows[0]->metadata ?? []))->toMatchArray(['wave' => $w['wave']->ulid, 'market' => $w['mk']['SK'], 'cells_created' => 1, 'angles_created' => 1, 'levels' => ['support' => 1]])
        ->and(($rows[1]->metadata ?? []))->toMatchArray(['cells_updated' => 1, 'angles_updated' => 1, 'changed' => ['message_line']])
        ->and(json_encode($rows->pluck('metadata')))->not->toContain('SECRET');
    expect(json_encode($rows->pluck('metadata')))->not->toContain($w['p1']);
});

// --- roles, tenancy -------------------------------------------------------------------------------------------------

it('R-01: admin, manager and staff can read and write cells; staff cannot delete a USP that has cells, a manager can', function (): void {
    $w = mx();
    foreach (['admin', 'manager', 'staff'] as $who) {
        mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'level' => 'low', 'rationale' => $who], $who)->assertOk();
        S::call($w[$who], 'GET', S::u($w, "matrix?market={$w['mk']['SK']}"))->assertOk();
    }
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'clear' => true], 'staff')->assertOk();   // staff may clear
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uB'], 'level' => 'low'], 'staff')->assertOk();

    S::call($w['staff'], 'DELETE', S::u($w, "usps/{$w['uB']}?confirm_count=1"))->assertForbidden();
    expect(mxCellOrNull($w['p1'], $w['uB']))->not->toBeNull();
    S::call($w['manager'], 'DELETE', S::u($w, "usps/{$w['uB']}?confirm_count=1"))->assertNoContent();
    expect(WaveMatrixCell::query()->count())->toBe(0);
});

it('T-01: another agency cannot read or write the matrix of this wave, and a persona or USP of another agency or wave is a 404', function (): void {
    $w = mx();
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'level' => 'low'])->assertOk();
    $o = ['agency' => $w['otherAgency'], 'brand' => $w['otherBrand'], 'wave' => $w['otherWave']];

    S::call($w['otherAdmin'], 'GET', S::u($w, "matrix?market={$w['mk']['SK']}"))->assertNotFound();
    S::call($w['otherAdmin'], 'PUT', S::u($w, 'matrix/cells'), ['market' => $w['mk']['SK'], 'cells' => [['persona' => $w['p1'], 'usp' => $w['uA'], 'clear' => true]]])->assertNotFound();
    expect(mxCellOrNull($w['p1'], $w['uA']))->not->toBeNull();

    // The other agency's own wave works, but it cannot name this agency's persona or USP.
    $theirUsp = S::call($w['otherAdmin'], 'POST', S::url($o['agency'], $o['brand'], $o['wave'], 'usps'), ['title' => 'Theirs'])->assertCreated()->json('data.id');
    $theirPersona = S::call($w['otherAdmin'], 'POST', S::url($o['agency'], $o['brand'], $o['wave'], 'personas'), ['market' => S::mk($o['wave'])['SK'], 'title' => 'T', 'name' => 'N'])->assertCreated()->json('data.id');
    $put = fn (array $cell) => S::call($w['otherAdmin'], 'PUT', S::url($o['agency'], $o['brand'], $o['wave'], 'matrix/cells'), ['market' => S::mk($o['wave'])['SK'], 'cells' => [$cell]]);

    $put(['persona' => $w['p1'], 'usp' => $theirUsp, 'level' => 'low'])->assertNotFound();
    $put(['persona' => $theirPersona, 'usp' => $w['uA'], 'level' => 'low'])->assertNotFound();
    $put(['persona' => $theirPersona, 'usp' => $theirUsp, 'level' => 'low'])->assertOk();
    expect(WaveMatrixCell::withoutGlobalScopes()->count())->toBe(2);
});

it('T-02: a persona of another market than the request\'s is a 404 and writes nothing', function (): void {
    $w = mx();
    mxPut($w, ['persona' => $w['r1'], 'usp' => $w['uA'], 'level' => 'low'], market: 'SK')->assertNotFound();
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'level' => 'low'], market: 'RO')->assertNotFound();

    expect(WaveMatrixCell::query()->count())->toBe(0);
});

it('T-03: a persona or a USP of ANOTHER WAVE of the same agency is a 404 on this wave\'s matrix', function (): void {
    $w = mx();
    $other = Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->create();
    $otherUrl = S::url($w['agency'], $w['brand'], $other, '');
    $theirUsp = S::call($w['admin'], 'POST', rtrim($otherUrl, '/').'/usps', ['title' => 'Elsewhere'])->assertCreated()->json('data.id');
    $theirPersona = S::call($w['admin'], 'POST', rtrim($otherUrl, '/').'/personas', ['market' => S::mk($other)['SK'], 'title' => 'T', 'name' => 'N'])->assertCreated()->json('data.id');

    mxPut($w, ['persona' => $w['p1'], 'usp' => $theirUsp, 'level' => 'low'])->assertNotFound();
    mxPut($w, ['persona' => $theirPersona, 'usp' => $w['uA'], 'level' => 'low'])->assertNotFound();
    expect(WaveMatrixCell::withoutGlobalScopes()->count())->toBe(0);
});

/*
 * PL-2.2 ruling change (2026-11-20, amends X7): a hand-written angle and a cell for the same pair must not sit there with two
 * different texts without the user seeing it and being able to resolve it. Rows 25 to 31 of the sync table.
 */

/** @return TestResponse<Response> */
function mxUse(array $w, string $persona, string $angle, string $who = 'admin'): TestResponse
{
    return S::call($w[$who], 'POST', S::u($w, "personas/{$persona}/angles/{$angle}/use-matrix-line"));
}

it('X-25 (R1 data): the matrix answers the text of the hand-written angle of a pair, for the editor to prefill an empty line', function (): void {
    $w = mx();
    $manualId = mxManual($w, $w['p1'], $w['uA'], 'Mine, by hand');

    $cells = S::call($w['admin'], 'GET', S::u($w, 'matrix')."?market={$w['mk']['SK']}")->assertOk()->json('data.attributes.cells');
    expect($cells)->toBe([]);
    // No cell yet, and the pair is still listed: a cell that is not set can be prefilled from the hand-written angle.
    S::call($w['admin'], 'GET', S::u($w, 'matrix')."?market={$w['mk']['SK']}")->assertOk()
        ->assertJsonPath('data.attributes.manual_angles', [['persona' => $w['p1'], 'usp' => $w['uA'], 'angle' => $manualId, 'text' => 'Mine, by hand']]);

    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'level' => 'support', 'message_line' => 'Lead line'])->assertOk()
        ->assertJsonPath('data.cells.0.manual_text', 'Mine, by hand');
    S::call($w['admin'], 'GET', S::u($w, 'matrix')."?market={$w['mk']['SK']}")->assertOk()
        ->assertJsonPath('data.attributes.cells.0.manual_text', 'Mine, by hand')
        ->assertJsonPath('data.attributes.cells.0.manual_angle', $manualId)
        ->assertJsonPath('data.attributes.cells.0.has_manual_angle', true);

    mxPut($w, ['persona' => $w['p2'], 'usp' => $w['uA'], 'level' => 'low'])->assertOk()->assertJsonPath('data.cells.0.manual_text', null);
});

it('X-26 (R2): "Use the matrix line" turns the hand-written angle into the synced one, same row, text = message line, one audit row without a text', function (): void {
    $w = mx();
    $manual = mxManual($w, $w['p1'], $w['uA'], 'Mine, by hand');
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'level' => 'priority', 'message_line' => 'From the cell'])->assertOk();
    $id = mxAngle($w['p1'], $w['uA'])->id;

    mxUse($w, $w['p1'], $manual)->assertOk()
        ->assertJsonPath('data.attributes.origin', 'matrix')->assertJsonPath('data.attributes.read_only', true)
        ->assertJsonPath('data.attributes.explanation', 'From the cell');

    $angle = mxAngle($w['p1'], $w['uA']);
    expect(WavePersonaAngle::query()->count())->toBe(1)
        ->and($angle->id)->toBe($id)
        ->and($angle->origin)->toBe(AngleOrigin::Matrix)
        ->and($angle->explanation)->toBe('From the cell');
    $rows = AuditLog::query()->where('action', 'wave_persona_angle.matrix_line_used')->get();
    expect($rows)->toHaveCount(1);
    $metadata = $rows->firstOrFail()->metadata;
    $encoded = (string) json_encode($metadata);
    expect($metadata)->toMatchArray(['angles_converted' => 1, 'level' => 'priority'])
        ->and(str_contains($encoded, 'Mine, by hand'))->toBeFalse()
        ->and(str_contains($encoded, 'From the cell'))->toBeFalse();
    mxInvariant();
});

it('X-27 (R2): equal texts are nothing to resolve: 422, nothing written', function (): void {
    $w = mx();
    $manual = mxManual($w, $w['p1'], $w['uA'], 'Same words');
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'level' => 'priority', 'message_line' => 'Same words'])->assertOk();
    $before = mxAngle($w['p1'], $w['uA'])->only(['id', 'explanation', 'origin', 'updated_at']);

    mxUse($w, $w['p1'], $manual)->assertStatus(422)->assertJsonPath('errors.0.code', 'angle.nothing_to_resolve');

    expect(mxAngle($w['p1'], $w['uA'])->only(['id', 'explanation', 'origin', 'updated_at']))->toEqual($before)
        ->and(AuditLog::query()->where('action', 'wave_persona_angle.matrix_line_used')->count())->toBe(0);
});

it('X-28 (R2): a Low cell, no cell, or a cell without a message line has no line to use: 422, the angle stays as written', function (): void {
    $w = mx();
    $a = mxManual($w, $w['p1'], $w['uA'], 'Mine');
    mxUse($w, $w['p1'], $a)->assertStatus(422)->assertJsonPath('errors.0.code', 'angle.no_matrix_line');   // no cell

    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'level' => 'low', 'message_line' => 'A line on a Low cell'])->assertOk();
    mxUse($w, $w['p1'], $a)->assertStatus(422)->assertJsonPath('errors.0.code', 'angle.no_matrix_line');   // Low

    expect(mxAngle($w['p1'], $w['uA'])->explanation)->toBe('Mine')
        ->and(mxAngle($w['p1'], $w['uA'])->origin)->toBe(AngleOrigin::Manual)
        ->and(AuditLog::query()->where('action', 'wave_persona_angle.matrix_line_used')->count())->toBe(0);
});

it('X-29 (R2): pressing it twice is the same as once: the second answer is 200, nothing written, nothing audited', function (): void {
    $w = mx();
    $manual = mxManual($w, $w['p1'], $w['uA'], 'Mine');
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'level' => 'support', 'message_line' => 'From the cell'])->assertOk();
    mxUse($w, $w['p1'], $manual)->assertOk();
    $before = mxAngle($w['p1'], $w['uA'])->only(['id', 'explanation', 'origin', 'updated_at']);

    mxUse($w, $w['p1'], $manual)->assertOk()->assertJsonPath('data.attributes.origin', 'matrix');

    expect(mxAngle($w['p1'], $w['uA'])->only(['id', 'explanation', 'origin', 'updated_at']))->toEqual($before)
        ->and(AuditLog::query()->where('action', 'wave_persona_angle.matrix_line_used')->count())->toBe(1);
});

it('X-30 (R2): admin, manager and staff may all use it; another agency gets a 404 and an unknown angle a 404', function (): void {
    $w = mx();
    foreach (['admin', 'manager', 'staff'] as $i => $who) {
        $persona = $i === 0 ? $w['p1'] : $w['p2'];
        $usp = $i === 2 ? $w['uB'] : $w['uA'];
        $angle = mxManual($w, $persona, $usp, "Mine {$who}");
        mxPut($w, ['persona' => $persona, 'usp' => $usp, 'level' => 'priority', 'message_line' => "Cell {$who}"])->assertOk();
        mxUse($w, $persona, $angle, $who)->assertOk()->assertJsonPath('data.attributes.explanation', "Cell {$who}");
    }

    $o = $w;
    $b = mxManual($w, $w['p1'], $w['uC'], 'Mine again');
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uC'], 'level' => 'priority', 'message_line' => 'Cell again'])->assertOk();
    S::call($w['otherAdmin'], 'POST', S::url($o['agency'], $o['brand'], $o['wave'], "personas/{$w['p1']}/angles/{$b}/use-matrix-line"))->assertNotFound();
    mxUse($w, $w['p1'], '01HZZZZZZZZZZZZZZZZZZZZZZZ')->assertNotFound();
    expect(mxAngle($w['p1'], $w['uC'])->origin)->toBe(AngleOrigin::Manual);
});

it('X-31 (R4): a cell save alone never changes the hand-written angle, even when the two texts differ', function (): void {
    $w = mx();
    $manual = mxManual($w, $w['p1'], $w['uA'], 'Mine');
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'level' => 'priority', 'message_line' => 'One'])->assertOk();
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'message_line' => 'Two'])->assertOk()->assertJsonPath('data.cells.0.manual_text', 'Mine');

    $angle = mxAngle($w['p1'], $w['uA']);
    expect($angle->ulid)->toBe($manual)->and($angle->explanation)->toBe('Mine')->and($angle->origin)->toBe(AngleOrigin::Manual);
});

it('X-32 (R3 data): the persona card gets the message line of each of its cells, to compare with its hand-written angles', function (): void {
    $w = mx();
    mxManual($w, $w['p1'], $w['uA'], 'Mine');
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'level' => 'priority', 'message_line' => 'From the cell'])->assertOk();

    $cards = S::call($w['admin'], 'GET', S::u($w, 'personas')."?market={$w['mk']['SK']}")->assertOk()->json('data');
    $card = array_values(array_filter($cards, fn (array $c): bool => $c['id'] === $w['p1']))[0];
    expect($card['attributes']['matrix_cells'])->toBe([['usp' => $w['uA'], 'level' => 'priority', 'message_line' => 'From the cell']]);
});

/*
 * PL-2.2 walk defect D1 (2026-11-20): staff must not delete a USP that is in use. The three cases of "in use", each judged
 * by the server at the moment of the delete (not by what the page showed): cells only, hand-written angles only, both.
 */
dataset('d1_in_use_cases', [
    'D1-a: cells only' => ['cells'],
    'D1-b: angles only' => ['angles'],
    'D1-c: cells and angles' => ['both'],
]);

it('D1: staff cannot delete a USP in use (403, with or without a count); nothing is removed or audited; a manager can with the count', function (string $case): void {
    $w = mx();
    if ($case === 'cells' || $case === 'both') {
        mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'level' => 'low'])->assertOk();
    }
    if ($case === 'angles' || $case === 'both') {
        mxManual($w, $w['p2'], $w['uA'], 'Mine');
    }
    $items = $case === 'both' ? 2 : 1;
    $usp = WaveUsp::query()->where('ulid', $w['uA'])->firstOrFail();

    foreach (['', "?confirm_count={$items}", '?confirm_count=0'] as $query) {
        S::call($w['staff'], 'DELETE', S::u($w, "usps/{$w['uA']}{$query}"))->assertForbidden()->assertJsonPath('errors.0.code', 'forbidden');
    }

    expect(WaveUsp::query()->whereKey($usp->id)->exists())->toBeTrue()
        ->and(WaveMatrixCell::query()->where('usp_id', $usp->id)->count())->toBe($case === 'angles' ? 0 : 1)
        ->and(WavePersonaAngle::query()->where('usp_id', $usp->id)->count())->toBe($case === 'cells' ? 0 : 1)
        ->and(AuditLog::query()->where('action', 'wave_usp.deleted')->count())->toBe(0);

    S::call($w['manager'], 'DELETE', S::u($w, "usps/{$w['uA']}?confirm_count={$items}"))->assertNoContent();
    expect(WaveUsp::query()->whereKey($usp->id)->exists())->toBeFalse();
})->with('d1_in_use_cases');

it('D1: the server judges "in use" at the delete, so a page that showed the USP as unused (a stale list) still cannot let staff delete it', function (): void {
    $w = mx();
    S::call($w['staff'], 'GET', S::u($w, 'usps'))->assertOk()->assertJsonPath('data.0.attributes.cell_count', 0);   // what the page showed
    mxPut($w, ['persona' => $w['p1'], 'usp' => $w['uA'], 'level' => 'priority', 'message_line' => 'Line'], who: 'manager')->assertOk();   // someone else, meanwhile

    S::call($w['staff'], 'DELETE', S::u($w, "usps/{$w['uA']}"))->assertForbidden();
    expect(WaveUsp::query()->where('ulid', $w['uA'])->exists())->toBeTrue()->and(WaveMatrixCell::query()->count())->toBe(1);
});
