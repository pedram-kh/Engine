<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Brands\Enums\BrandStatus;
use App\Modules\Strategy\Models\WavePersona;
use App\Modules\Strategy\Models\WavePersonaAngle;
use App\Modules\Strategy\Models\WaveUsp;
use App\Modules\Waves\Models\Wave;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\StrategyFixtures as S;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * PL-2.1 — personas and angles. Gates: L3 L4 (cap and slot), M1 M2 (markets, copy), U1 (angle USP of the same wave),
 * R1 R5 (roles), X1 X3 (text), A1 (audit), T1 T3 (tenancy), and the pronoun and code rules.
 */

/** @return array<string, mixed> */
function personaBody(array $o = []): array
{
    return array_merge(['market' => S::$mk['SK'], 'title' => 'The Office Lunch Organiser', 'name' => 'Zuzana', 'pronoun' => 'she'], $o);
}

/** @return list<string> */
function makePersonas(array $w, int $n, string $market = 'SK'): array
{
    $ids = [];
    for ($i = 1; $i <= $n; $i++) {
        $ids[] = S::call($w['admin'], 'POST', S::u($w, 'personas'), personaBody(['market' => S::$mk[$market], 'name' => "P{$i}"]))->assertCreated()->json('data.id');
    }

    return $ids;
}

function makeUsp(array $w, string $title = 'Affordability'): string
{
    return S::call($w['admin'], 'POST', S::u($w, 'usps'), ['title' => $title])->assertCreated()->json('data.id');
}

it('creates a persona with slot, default code, colour and the defaults; lists it with its angles', function (): void {
    $w = S::world();

    $row = S::call($w['staff'], 'POST', S::u($w, 'personas'), personaBody([
        'summary' => 'Orders for the team.', 'core_job' => 'Feed six people.', 'need' => 'One order.',
        'drivers' => ['Avoid hassle', 'Be fair'], 'goals' => ['Quick'], 'self_talk' => ['Ugh'], 'barriers' => ['Cost'],
    ]))->assertCreated()->json('data');

    expect($row['type'])->toBe('wave_persona')
        ->and($row['attributes'])->toMatchArray(['market' => $w['mk']['SK'], 'slot' => 1, 'code' => 'SK1', 'color_key' => 'violet', 'pronoun' => 'she', 'drivers' => ['Avoid hassle', 'Be fair'], 'angles' => []]);

    $they = S::call($w['admin'], 'POST', S::u($w, 'personas'), ['market' => $w['mk']['RO'], 'title' => 'T', 'name' => 'N'])->assertCreated()->json('data.attributes');
    expect($they['pronoun'])->toBe('they')->and($they['code'])->toBe('RO1')->and($they['drivers'])->toBe([]);

    expect(S::call($w['admin'], 'GET', S::u($w, "personas?market={$w['mk']['SK']}"))->assertOk()->json('data'))->toHaveCount(1);
    expect(S::call($w['admin'], 'GET', S::u($w, 'personas'))->assertOk()->json('data'))->toHaveCount(2);
});

it('L3: the 9th persona in a market is 422 persona.max_reached; another market is free', function (): void {
    $w = S::world();
    makePersonas($w, 8);

    S::call($w['admin'], 'POST', S::u($w, 'personas'), personaBody())->assertUnprocessable()->assertJsonPath('errors.0.code', 'persona.max_reached');
    expect(WavePersona::query()->where('market_id', $w['mkid']['SK'])->count())->toBe(8);
    S::call($w['admin'], 'POST', S::u($w, 'personas'), personaBody(['market' => $w['mk']['RO']]))->assertCreated();
});

it('L4: a freed slot is re-used, slot 7 and 8 wrap the colours, the default code follows the slot', function (): void {
    $w = S::world();
    $ids = makePersonas($w, 8);

    $colors = WavePersona::query()->orderBy('slot')->get()->map(fn ($p) => $p->colorKey()->value)->all();
    expect($colors)->toBe(['violet', 'green', 'blue', 'amber', 'teal', 'pink', 'violet', 'green']);

    S::call($w['admin'], 'DELETE', S::u($w, "personas/{$ids[2]}"))->assertNoContent();   // slot 3
    $new = S::call($w['admin'], 'POST', S::u($w, 'personas'), personaBody())->assertCreated()->json('data.attributes');
    expect($new['slot'])->toBe(3)->and($new['code'])->toBe('SK3');
});

it('codes: edited codes are upper-cased, 2 to 8 of A-Z 0-9, unique inside a market and free in another', function (): void {
    $w = S::world();
    [$a, $b] = makePersonas($w, 2);
    $other = makePersonas($w, 1, 'RO')[0];

    S::call($w['admin'], 'PATCH', S::u($w, "personas/{$a}"), ['code' => 'sk-a'])->assertUnprocessable();
    S::call($w['admin'], 'PATCH', S::u($w, "personas/{$a}"), ['code' => 'A'])->assertUnprocessable();
    S::call($w['admin'], 'PATCH', S::u($w, "personas/{$a}"), ['code' => 'ABCDEFGHI'])->assertUnprocessable();
    S::call($w['admin'], 'PATCH', S::u($w, "personas/{$a}"), ['code' => null])->assertUnprocessable();
    S::call($w['admin'], 'PATCH', S::u($w, "personas/{$a}"), ['code' => 'sk2'])->assertUnprocessable()->assertJsonPath('errors.0.code', 'persona.code_taken');
    S::call($w['admin'], 'PATCH', S::u($w, "personas/{$a}"), ['code' => 'ska'])->assertOk()->assertJsonPath('data.attributes.code', 'SKA');
    S::call($w['admin'], 'PATCH', S::u($w, "personas/{$other}"), ['code' => 'SKA'])->assertOk();   // another market
    S::call($w['admin'], 'PATCH', S::u($w, "personas/{$a}"), ['code' => 'SKA'])->assertOk();   // own code again: not a clash
    S::call($w['admin'], 'POST', S::u($w, 'personas'), personaBody(['code' => 'ska']))->assertUnprocessable()->assertJsonPath('errors.0.code', 'persona.code_taken');
    S::call($w['admin'], 'POST', S::u($w, 'personas'), personaBody(['code' => 'ok7']))->assertCreated()->assertJsonPath('data.attributes.code', 'OK7');
    expect($b)->toBeString();
});

it('a default code that another persona edited into is not reused: the next free suffix is taken', function (): void {
    $w = S::world();
    [$a] = makePersonas($w, 1);   // slot 1, SK1
    S::call($w['admin'], 'PATCH', S::u($w, "personas/{$a}"), ['code' => 'SK2'])->assertOk();   // takes the next default
    $second = S::call($w['admin'], 'POST', S::u($w, 'personas'), personaBody())->assertCreated()->json('data.attributes');

    expect($second['slot'])->toBe(2)->and($second['code'])->toBe('SK2A');
});

it('M1: a market that is not the wave\'s is 422 persona.market_not_in_wave on create and list', function (): void {
    $w = S::world();

    S::call($w['admin'], 'POST', S::u($w, 'personas'), personaBody(['market' => S::NO_MARKET]))->assertUnprocessable()->assertJsonPath('errors.0.code', 'persona.market_not_in_wave');
    S::call($w['admin'], 'GET', S::u($w, 'personas?market='.S::NO_MARKET))->assertUnprocessable()->assertJsonPath('errors.0.code', 'persona.market_not_in_wave');
    S::call($w['admin'], 'POST', S::u($w, 'personas'), personaBody(['market' => 'sk']))->assertUnprocessable();
    expect(WavePersona::query()->count())->toBe(0);
});

it('X1: every persona text limit holds at the boundary and one over; required fields; lists', function (): void {
    $w = S::world();
    $url = S::u($w, 'personas');

    foreach (['title' => 60, 'name' => 40, 'summary' => 300, 'core_job' => 300, 'need' => 300] as $field => $max) {
        S::call($w['admin'], 'POST', $url, personaBody([$field => str_repeat('é', $max), 'market' => $w['mk']['CZ']]))->assertCreated();
        S::call($w['admin'], 'POST', $url, personaBody([$field => str_repeat('é', $max + 1), 'market' => $w['mk']['CZ']]))->assertUnprocessable()
            ->assertJsonPath('errors.0.meta.field', $field);
        WavePersona::query()->delete();
    }
    S::call($w['admin'], 'POST', $url, ['market' => $w['mk']['SK'], 'name' => 'x'])->assertUnprocessable();
    S::call($w['admin'], 'POST', $url, ['market' => $w['mk']['SK'], 'title' => 'x'])->assertUnprocessable();
    S::call($w['admin'], 'POST', $url, personaBody(['pronoun' => 'it']))->assertUnprocessable();

    S::call($w['admin'], 'POST', $url, personaBody(['drivers' => array_fill(0, 6, str_repeat('a', 120))]))->assertCreated();
    S::call($w['admin'], 'POST', $url, personaBody(['drivers' => array_fill(0, 7, 'a')]))->assertUnprocessable();
    S::call($w['admin'], 'POST', $url, personaBody(['goals' => [str_repeat('a', 121)]]))->assertUnprocessable();
    S::call($w['admin'], 'POST', $url, personaBody(['barriers' => 'nope']))->assertUnprocessable();
    // Empty items are dropped and the order kept.
    $row = S::call($w['admin'], 'POST', $url, personaBody(['self_talk' => ['  one ', '', '   ', "two\x00"]]))->assertCreated()->json('data.attributes');
    expect($row['self_talk'])->toBe(['one', 'two']);
});

it('X3: persona text is stored as plain text: tags kept, control characters removed', function (): void {
    $w = S::world();

    $row = S::call($w['admin'], 'POST', S::u($w, 'personas'), personaBody([
        'name' => "<script>alert(1)</script>\x07", 'summary' => "a\r\nb",
    ]))->assertCreated()->json('data.attributes');

    expect($row['name'])->toBe('<script>alert(1)</script>')->and($row['summary'])->toBe("a\nb");
});

it('updates only the fields sent, never the market, writes no audit row when nothing changed', function (): void {
    $w = S::world();
    $id = makePersonas($w, 1)[0];

    S::call($w['admin'], 'PATCH', S::u($w, "personas/{$id}"), ['market' => $w['mk']['RO']])->assertUnprocessable();
    S::call($w['admin'], 'PATCH', S::u($w, "personas/{$id}"), ['title' => 'New'])->assertOk();
    S::call($w['manager'], 'PATCH', S::u($w, "personas/{$id}"), ['name' => 'Eva', 'drivers' => ['x']])->assertOk();
    $p = WavePersona::query()->firstOrFail();
    expect($p->title)->toBe('New')->and($p->name)->toBe('Eva')->and($p->pronoun->value)->toBe('she')->and($p->market_id)->toBe($w['mkid']['SK']);

    S::call($w['admin'], 'PATCH', S::u($w, "personas/{$id}"), ['title' => null])->assertUnprocessable();
    S::call($w['admin'], 'PATCH', S::u($w, "personas/{$id}"), ['summary' => null])->assertOk();

    $count = AuditLog::query()->where('action', 'wave_persona.updated')->count();
    S::call($w['admin'], 'PATCH', S::u($w, "personas/{$id}"), [])->assertOk();
    S::call($w['admin'], 'PATCH', S::u($w, "personas/{$id}"), ['title' => 'New', 'drivers' => ['x']])->assertOk();
    expect(AuditLog::query()->where('action', 'wave_persona.updated')->count())->toBe($count);
});

it('deletes a persona with its angles and nothing else (every role), one audit row with the angle count', function (): void {
    $w = S::world();
    [$a, $b] = makePersonas($w, 2);
    $usp = makeUsp($w);
    S::call($w['admin'], 'POST', S::u($w, "personas/{$a}/angles"), ['usp' => $usp, 'explanation' => 'Because.'])->assertCreated();
    S::call($w['admin'], 'POST', S::u($w, "personas/{$b}/angles"), ['usp' => $usp, 'explanation' => 'Also.'])->assertCreated();

    S::call($w['staff'], 'DELETE', S::u($w, "personas/{$a}"))->assertNoContent();
    expect(WavePersona::query()->count())->toBe(1)->and(WavePersonaAngle::query()->count())->toBe(1)->and(WaveUsp::query()->count())->toBe(1);
    expect(AuditLog::query()->where('action', 'wave_persona.deleted')->firstOrFail()->metadata)->toMatchArray(['angles_removed' => 1]);
});

it('L6: deleting down to zero personas is allowed (the minimum only warns)', function (): void {
    $w = S::world();
    foreach (makePersonas($w, 2) as $id) {
        S::call($w['admin'], 'DELETE', S::u($w, "personas/{$id}"))->assertNoContent();
    }
    expect(WavePersona::query()->count())->toBe(0);
});

it('angles: add, list on the persona with the USP colour and title, edit, remove; one per USP', function (): void {
    $w = S::world();
    $p = makePersonas($w, 1)[0];
    $u1 = makeUsp($w, 'One');
    $u2 = makeUsp($w, 'Two');

    $angle = S::call($w['staff'], 'POST', S::u($w, "personas/{$p}/angles"), ['usp' => $u2, 'explanation' => '  A line  '])->assertCreated()->json('data');
    expect($angle['attributes'])->toMatchArray(['usp' => $u2, 'usp_title' => 'Two', 'color_key' => 'green', 'explanation' => 'A line', 'origin' => 'manual']);

    S::call($w['admin'], 'POST', S::u($w, "personas/{$p}/angles"), ['usp' => $u2, 'explanation' => 'Again'])->assertUnprocessable()->assertJsonPath('errors.0.code', 'angle.already_linked');
    S::call($w['admin'], 'POST', S::u($w, "personas/{$p}/angles"), ['usp' => $u1, 'explanation' => 'First'])->assertCreated();

    $listed = S::call($w['admin'], 'GET', S::u($w, "personas?market={$w['mk']['SK']}"))->json('data.0.attributes.angles');
    expect(array_column(array_column($listed, 'attributes'), 'usp_title'))->toBe(['Two', 'One']);   // insertion order

    S::call($w['manager'], 'PATCH', S::u($w, "personas/{$p}/angles/{$angle['id']}"), ['explanation' => 'Edited'])->assertOk()->assertJsonPath('data.attributes.explanation', 'Edited');
    S::call($w['admin'], 'PATCH', S::u($w, "personas/{$p}/angles/{$angle['id']}"), ['explanation' => ''])->assertUnprocessable();
    S::call($w['staff'], 'DELETE', S::u($w, "personas/{$p}/angles/{$angle['id']}"))->assertNoContent();
    expect(WavePersonaAngle::query()->count())->toBe(1);
    // After removal the USP can be linked again.
    S::call($w['admin'], 'POST', S::u($w, "personas/{$p}/angles"), ['usp' => $u2, 'explanation' => 'Back'])->assertCreated();
});

it('X1: the angle explanation is required and at most 140 characters', function (): void {
    $w = S::world();
    $p = makePersonas($w, 1)[0];
    $usps = [makeUsp($w, 'a'), makeUsp($w, 'b'), makeUsp($w, 'c')];
    $url = S::u($w, "personas/{$p}/angles");

    S::call($w['admin'], 'POST', $url, ['usp' => $usps[0], 'explanation' => str_repeat('é', 140)])->assertCreated();
    S::call($w['admin'], 'POST', $url, ['usp' => $usps[1], 'explanation' => str_repeat('é', 141)])->assertUnprocessable()->assertJsonPath('errors.0.meta.field', 'explanation');
    S::call($w['admin'], 'POST', $url, ['usp' => $usps[1], 'explanation' => '   '])->assertUnprocessable();
    S::call($w['admin'], 'POST', $url, ['explanation' => 'x'])->assertUnprocessable();
});

it('U1: an angle can only link a USP of the same wave: another wave, another agency or unknown is 404', function (): void {
    $w = S::world();
    $p = makePersonas($w, 1)[0];
    $sibling = Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->create();
    $inSibling = WaveUsp::factory()->create(['wave_id' => $sibling->id, 'agency_id' => $w['agency']->id]);
    $foreign = WaveUsp::factory()->create(['wave_id' => $w['otherWave']->id, 'agency_id' => $w['otherAgency']->id]);

    foreach ([$inSibling->ulid, $foreign->ulid, '01HZZZZZZZZZZZZZZZZZZZZZZZ'] as $ulid) {
        S::call($w['admin'], 'POST', S::u($w, "personas/{$p}/angles"), ['usp' => $ulid, 'explanation' => 'x'])->assertNotFound();
    }
    expect(WavePersonaAngle::query()->count())->toBe(0);
});

it('refuses a persona or angle of another wave, agency or persona with 404', function (): void {
    $w = S::world();
    [$mine, $mine2] = makePersonas($w, 2);
    $usp = makeUsp($w);
    $angle = S::call($w['admin'], 'POST', S::u($w, "personas/{$mine}/angles"), ['usp' => $usp, 'explanation' => 'x'])->json('data.id');
    $foreign = WavePersona::factory()->create(['wave_id' => $w['otherWave']->id, 'agency_id' => $w['otherAgency']->id]);

    // A persona of a SIBLING wave (same agency and brand) is also 404: cards are found through the wave.
    $sibling = WavePersona::factory()->create(['wave_id' => Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->create()->id, 'agency_id' => $w['agency']->id]);
    S::call($w['admin'], 'PATCH', S::u($w, "personas/{$sibling->ulid}"), ['title' => 'x'])->assertNotFound();
    S::call($w['admin'], 'DELETE', S::u($w, "personas/{$sibling->ulid}"))->assertNotFound();
    S::call($w['admin'], 'POST', S::u($w, "personas/{$sibling->ulid}/angles"), ['usp' => $usp, 'explanation' => 'x'])->assertNotFound();
    S::call($w['admin'], 'PATCH', S::u($w, "personas/{$foreign->ulid}"), ['title' => 'x'])->assertNotFound();
    S::call($w['admin'], 'DELETE', S::u($w, "personas/{$foreign->ulid}"))->assertNotFound();
    S::call($w['admin'], 'POST', S::u($w, "personas/{$foreign->ulid}/angles"), ['usp' => $usp, 'explanation' => 'x'])->assertNotFound();
    // An angle addressed through another persona of the same wave.
    S::call($w['admin'], 'PATCH', S::u($w, "personas/{$mine2}/angles/{$angle}"), ['explanation' => 'hijack'])->assertNotFound();
    S::call($w['admin'], 'DELETE', S::u($w, "personas/{$mine2}/angles/{$angle}"))->assertNotFound();
    expect(WavePersonaAngle::query()->count())->toBe(1)->and(WavePersonaAngle::query()->value('explanation'))->toBe('x');
});

it('M2: copy brings every persona with its angles, new slots and default codes, and the originals stay', function (): void {
    $w = S::world();
    $ids = makePersonas($w, 3);
    $usp = makeUsp($w);
    S::call($w['admin'], 'POST', S::u($w, "personas/{$ids[0]}/angles"), ['usp' => $usp, 'explanation' => 'Line'])->assertCreated();
    S::call($w['admin'], 'PATCH', S::u($w, "personas/{$ids[0]}"), ['code' => 'MINE'])->assertOk();
    makePersonas($w, 1, 'RO');   // RO already has one (slot 1)

    $res = S::call($w['staff'], 'POST', S::u($w, 'personas/copy'), ['from_market' => $w['mk']['SK'], 'to_market' => $w['mk']['RO']])->assertCreated();
    $rows = $res->json('data');
    expect($rows)->toHaveCount(3)
        ->and(array_column(array_column($rows, 'attributes'), 'slot'))->toBe([2, 3, 4])
        ->and(array_column(array_column($rows, 'attributes'), 'code'))->toBe(['RO2', 'RO3', 'RO4'])   // the edited code is not carried
        ->and($rows[0]['attributes']['angles'])->toHaveCount(1)
        ->and($rows[0]['attributes']['angles'][0]['attributes']['usp'])->toBe($usp);
    expect(WavePersona::query()->where('market_id', $w['mkid']['SK'])->count())->toBe(3)->and(WavePersonaAngle::query()->count())->toBe(2);
    expect(AuditLog::query()->where('action', 'wave_persona.copied')->firstOrFail()->metadata)->toMatchArray(['from_market' => $w['mk']['SK'], 'to_market' => $w['mk']['RO'], 'personas_copied' => 3]);
});

it('M2: copy can take chosen personas only', function (): void {
    $w = S::world();
    $ids = makePersonas($w, 3);

    S::call($w['admin'], 'POST', S::u($w, 'personas/copy'), ['from_market' => $w['mk']['SK'], 'to_market' => $w['mk']['CZ'], 'persona_ids' => [$ids[2], $ids[0]]])->assertCreated();
    expect(WavePersona::query()->where('market_id', $w['mkid']['CZ'])->orderBy('slot')->pluck('name')->all())->toBe(['P1', 'P3']);
});

it('M2: copy over 8 is 422 persona.copy_exceeds_cap with the numbers and NOTHING is copied', function (): void {
    $w = S::world();
    makePersonas($w, 3);
    makePersonas($w, 6, 'RO');
    $before = WavePersona::query()->count();

    $res = S::call($w['admin'], 'POST', S::u($w, 'personas/copy'), ['from_market' => $w['mk']['SK'], 'to_market' => $w['mk']['RO']])->assertUnprocessable();
    expect($res->json('errors.0.code'))->toBe('persona.copy_exceeds_cap')
        ->and($res->json('errors.0.meta'))->toMatchArray(['target_count' => 6, 'copy_count' => 3, 'max' => 8])
        ->and(WavePersona::query()->count())->toBe($before)->and(WavePersonaAngle::query()->count())->toBe(0);
});

it('M2: copy refuses same market, a market not in the wave, nothing to copy, and foreign persona ids', function (): void {
    $w = S::world();
    $ids = makePersonas($w, 1);
    $other = makePersonas($w, 1, 'RO')[0];
    $url = S::u($w, 'personas/copy');

    S::call($w['admin'], 'POST', $url, ['from_market' => $w['mk']['SK'], 'to_market' => $w['mk']['SK']])->assertUnprocessable()->assertJsonPath('errors.0.code', 'persona.copy_invalid');
    S::call($w['admin'], 'POST', $url, ['from_market' => $w['mk']['SK'], 'to_market' => S::NO_MARKET])->assertUnprocessable()->assertJsonPath('errors.0.code', 'persona.market_not_in_wave');
    S::call($w['admin'], 'POST', $url, ['from_market' => S::NO_MARKET, 'to_market' => $w['mk']['SK']])->assertUnprocessable()->assertJsonPath('errors.0.code', 'persona.market_not_in_wave');
    S::call($w['admin'], 'POST', $url, ['from_market' => $w['mk']['CZ'], 'to_market' => $w['mk']['SK']])->assertUnprocessable()->assertJsonPath('errors.0.code', 'persona.copy_invalid');
    S::call($w['admin'], 'POST', $url, ['from_market' => $w['mk']['SK'], 'to_market' => $w['mk']['CZ'], 'persona_ids' => [$other]])->assertUnprocessable()->assertJsonPath('errors.0.code', 'persona.copy_invalid');
    S::call($w['admin'], 'POST', $url, ['from_market' => $w['mk']['SK'], 'to_market' => $w['mk']['CZ'], 'persona_ids' => [$ids[0], '01HZZZZZZZZZZZZZZZZZZZZZZZ']])->assertUnprocessable();
    S::call($w['admin'], 'POST', $url, ['from_market' => $w['mk']['SK']])->assertUnprocessable();
    expect(WavePersona::query()->where('market_id', $w['mkid']['CZ'])->count())->toBe(0);
});

it('R1, R5: staff create, edit, copy, add angles and delete personas and angles', function (): void {
    $w = S::world();
    $p = S::call($w['staff'], 'POST', S::u($w, 'personas'), personaBody())->assertCreated()->json('data.id');
    S::call($w['staff'], 'PATCH', S::u($w, "personas/{$p}"), ['name' => 'Eva'])->assertOk();
    $usp = makeUsp($w);
    $a = S::call($w['staff'], 'POST', S::u($w, "personas/{$p}/angles"), ['usp' => $usp, 'explanation' => 'x'])->assertCreated()->json('data.id');
    S::call($w['staff'], 'POST', S::u($w, 'personas/copy'), ['from_market' => $w['mk']['SK'], 'to_market' => $w['mk']['RO']])->assertCreated();
    S::call($w['staff'], 'DELETE', S::u($w, "personas/{$p}/angles/{$a}"))->assertNoContent();
    S::call($w['staff'], 'DELETE', S::u($w, "personas/{$p}"))->assertNoContent();
});

it('A1: each persona and angle write makes one audit row of the right action and never the text or the name', function (): void {
    $w = S::world();
    $p = S::call($w['admin'], 'POST', S::u($w, 'personas'), personaBody(['name' => 'SECRET-NAME', 'summary' => 'SECRET-SUMMARY']))->assertCreated()->json('data.id');
    S::call($w['admin'], 'PATCH', S::u($w, "personas/{$p}"), ['title' => 'SECRET-TITLE', 'drivers' => ['SECRET-DRIVER']])->assertOk();
    $usp = makeUsp($w);
    $a = S::call($w['admin'], 'POST', S::u($w, "personas/{$p}/angles"), ['usp' => $usp, 'explanation' => 'SECRET-LINE'])->assertCreated()->json('data.id');
    S::call($w['admin'], 'PATCH', S::u($w, "personas/{$p}/angles/{$a}"), ['explanation' => 'SECRET-LINE-2'])->assertOk();
    S::call($w['admin'], 'DELETE', S::u($w, "personas/{$p}/angles/{$a}"))->assertNoContent();
    S::call($w['admin'], 'POST', S::u($w, 'personas/copy'), ['from_market' => $w['mk']['SK'], 'to_market' => $w['mk']['RO']])->assertCreated();
    S::call($w['admin'], 'DELETE', S::u($w, "personas/{$p}"))->assertNoContent();

    $actions = ['wave_persona.created', 'wave_persona.updated', 'wave_persona_angle.added', 'wave_persona_angle.updated', 'wave_persona_angle.removed', 'wave_persona.copied', 'wave_persona.deleted'];
    $rows = AuditLog::query()->whereIn('action', $actions)->orderBy('id')->get();
    $meta = array_values($rows->pluck('metadata')->all());
    expect($rows->map(fn ($r) => is_string($r->action) ? $r->action : $r->action->value)->all())->toBe($actions)
        ->and($meta[0])->toBe(['wave' => $w['wave']->ulid, 'persona' => $p, 'market' => $w['mk']['SK']])
        ->and($meta[1])->toMatchArray(['changed' => ['title', 'drivers']])
        ->and($meta[2])->toBe(['wave' => $w['wave']->ulid, 'persona' => $p, 'angle' => $a, 'usp' => $usp, 'market' => $w['mk']['SK']])
        ->and($meta[3])->toMatchArray(['changed' => ['explanation']]);
    expect(json_encode($rows->map(fn ($r) => [$r->metadata, $r->before, $r->after])->all()))->not->toContain('SECRET');
});

it('T1: another agency is 404 on every persona and angle endpoint; a wrong brand too', function (): void {
    $w = S::world();
    $p = makePersonas($w, 1)[0];
    $usp = makeUsp($w);
    $a = S::call($w['admin'], 'POST', S::u($w, "personas/{$p}/angles"), ['usp' => $usp, 'explanation' => 'x'])->json('data.id');

    foreach ([
        ['GET', 'personas'], ['POST', 'personas'], ['POST', 'personas/copy'], ['PATCH', "personas/{$p}"], ['DELETE', "personas/{$p}"],
        ['POST', "personas/{$p}/angles"], ['PATCH', "personas/{$p}/angles/{$a}"], ['DELETE', "personas/{$p}/angles/{$a}"],
    ] as [$method, $suffix]) {
        S::call($w['otherAdmin'], $method, S::u($w, $suffix), personaBody() + ['usp' => $usp, 'explanation' => 'x', 'from_market' => $w['mk']['SK'], 'to_market' => $w['mk']['RO']])->assertNotFound();
    }
    S::call($w['admin'], 'GET', S::url($w['agency'], $w['otherBrand'], $w['wave'], 'personas'))->assertNotFound();
    expect(WavePersona::query()->count())->toBe(1)->and(WavePersonaAngle::query()->count())->toBe(1);
});

it('an archived brand is read-only for personas and angles', function (): void {
    $w = S::world();
    $p = makePersonas($w, 1)[0];
    $usp = makeUsp($w);
    $a = S::call($w['admin'], 'POST', S::u($w, "personas/{$p}/angles"), ['usp' => $usp, 'explanation' => 'x'])->json('data.id');
    $w['brand']->forceFill(['status' => BrandStatus::Archived])->save();

    S::call($w['admin'], 'GET', S::u($w, 'personas'))->assertOk();
    foreach ([
        ['POST', 'personas', personaBody()], ['POST', 'personas/copy', ['from_market' => $w['mk']['SK'], 'to_market' => $w['mk']['RO']]],
        ['PATCH', "personas/{$p}", ['title' => 'x']], ['DELETE', "personas/{$p}", []],
        ['POST', "personas/{$p}/angles", ['usp' => $usp, 'explanation' => 'y']], ['PATCH', "personas/{$p}/angles/{$a}", ['explanation' => 'z']],
        ['DELETE', "personas/{$p}/angles/{$a}", []],
    ] as [$method, $suffix, $body]) {
        S::call($w['admin'], $method, S::u($w, $suffix), $body)->assertUnprocessable()->assertJsonPath('errors.0.code', 'wave.brand_archived');
    }
    expect(WavePersona::query()->count())->toBe(1)->and(WavePersonaAngle::query()->count())->toBe(1);
});

it('C4: a persona delete is atomic (a failure after the angles are removed leaves everything)', function (): void {
    $w = S::world();
    $p = makePersonas($w, 1)[0];
    S::call($w['admin'], 'POST', S::u($w, "personas/{$p}/angles"), ['usp' => makeUsp($w), 'explanation' => 'x'])->assertCreated();

    AuditLog::creating(static function (): never {
        throw new RuntimeException('boom');
    });
    try {
        S::call($w['admin'], 'DELETE', S::u($w, "personas/{$p}"));
    } catch (Throwable) {
    }
    AuditLog::flushEventListeners();

    expect(WavePersona::query()->count())->toBe(1)->and(WavePersonaAngle::query()->count())->toBe(1);
});

it('copy is atomic: a failure midway leaves no persona in the target market', function (): void {
    $w = S::world();
    makePersonas($w, 3);

    AuditLog::creating(static function (): never {
        throw new RuntimeException('boom');
    });
    try {
        S::call($w['admin'], 'POST', S::u($w, 'personas/copy'), ['from_market' => $w['mk']['SK'], 'to_market' => $w['mk']['RO']]);
    } catch (Throwable) {
    }
    AuditLog::flushEventListeners();

    expect(WavePersona::query()->where('market_id', $w['mkid']['RO'])->count())->toBe(0);
});
