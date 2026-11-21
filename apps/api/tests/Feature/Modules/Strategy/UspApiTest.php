<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Brands\Enums\BrandStatus;
use App\Modules\Brands\Models\Brand;
use App\Modules\Strategy\Models\WavePersona;
use App\Modules\Strategy\Models\WavePersonaAngle;
use App\Modules\Strategy\Models\WaveUsp;
use App\Modules\Waves\Models\Wave;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\StrategyFixtures as S;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * PL-2.1 — the USP endpoints: create, list, update, reorder, delete with the in-use confirmation, the audience title.
 * Gates: L1 L6 (cap), O1-O4 (reorder), C1-C4 (delete), R1-R4 (roles), X1 X3 (text), A1 (audit), T1 T2 (tenancy).
 */

/** @return array<string, mixed> */
function uspBody(array $o = []): array
{
    return array_merge(['title' => 'Affordability', 'explainer' => 'Value, not price.', 'creative_angle' => 'Value perception', 'copy_seed' => '"A fair price."'], $o);
}

/** @return list<string> */
function makeUsps(array $w, int $n): array
{
    $ulids = [];
    for ($i = 1; $i <= $n; $i++) {
        $ulids[] = S::call($w['admin'], 'POST', S::u($w, 'usps'), uspBody(['title' => "USP {$i}"]))->assertCreated()->json('data.id');
    }

    return $ulids;
}

it('creates USPs with the colours in the S3 order and positions 1 to n, and lists them in order', function (): void {
    $w = S::world();
    makeUsps($w, 6);

    $rows = S::call($w['admin'], 'GET', S::u($w, 'usps'))->assertOk()->json('data');
    expect(array_column(array_column($rows, 'attributes'), 'color_key'))->toBe(['violet', 'green', 'blue', 'amber', 'teal', 'pink'])
        ->and(array_column(array_column($rows, 'attributes'), 'position'))->toBe([1, 2, 3, 4, 5, 6])
        ->and($rows[0]['type'])->toBe('wave_usp')
        ->and($rows[0]['attributes'])->not->toHaveKeys(['id', 'agency_id', 'wave_id']);
});

it('L1: the 7th USP is 422 usp.max_reached; deleting one and adding re-uses the freed colour', function (): void {
    $w = S::world();
    $ulids = makeUsps($w, 6);

    S::call($w['admin'], 'POST', S::u($w, 'usps'), uspBody())->assertUnprocessable()->assertJsonPath('errors.0.code', 'usp.max_reached');
    expect(WaveUsp::query()->count())->toBe(6);

    S::call($w['admin'], 'DELETE', S::u($w, "usps/{$ulids[2]}"))->assertNoContent();   // blue
    $new = S::call($w['admin'], 'POST', S::u($w, 'usps'), uspBody(['title' => 'Again']))->assertCreated();
    expect($new->json('data.attributes.color_key'))->toBe('blue')->and($new->json('data.attributes.position'))->toBe(6);
});

it('L6: deleting down to zero USPs is allowed (the minimum only warns)', function (): void {
    $w = S::world();
    foreach (makeUsps($w, 2) as $ulid) {
        S::call($w['admin'], 'DELETE', S::u($w, "usps/{$ulid}"))->assertNoContent();
    }
    expect(WaveUsp::query()->count())->toBe(0);
});

it('X1: the title is required, at most 40 characters (multibyte counted as characters), whitespace-only is refused', function (): void {
    $w = S::world();

    S::call($w['admin'], 'POST', S::u($w, 'usps'), ['title' => str_repeat('a', 41)])->assertUnprocessable()->assertJsonPath('errors.0.meta.field', 'title');
    S::call($w['admin'], 'POST', S::u($w, 'usps'), ['title' => '   '])->assertUnprocessable();
    S::call($w['admin'], 'POST', S::u($w, 'usps'), [])->assertUnprocessable();
    S::call($w['admin'], 'POST', S::u($w, 'usps'), ['title' => str_repeat('a', 40)])->assertCreated();
    S::call($w['admin'], 'POST', S::u($w, 'usps'), ['title' => str_repeat('é', 40)])->assertCreated();
    S::call($w['admin'], 'POST', S::u($w, 'usps'), ['title' => str_repeat('é', 41)])->assertUnprocessable();
});

it('X1: every text limit holds at the boundary and one over', function (): void {
    $w = S::world();
    foreach (['explainer' => 400, 'creative_angle' => 300, 'copy_seed' => 300] as $field => $max) {
        S::call($w['admin'], 'POST', S::u($w, 'usps'), ['title' => 'Ok', $field => str_repeat('x', $max)])->assertCreated();
        S::call($w['admin'], 'POST', S::u($w, 'usps'), ['title' => 'Ok', $field => str_repeat('x', $max + 1)])->assertUnprocessable()
            ->assertJsonPath('errors.0.meta.field', $field);
        // Delete the one created so the cap of 6 never interferes.
        WaveUsp::query()->latest('id')->first()?->delete();
    }
});

it('X3: text is stored verbatim minus control characters; NUL is removed; CRLF becomes LF; tags are not stripped', function (): void {
    $w = S::world();

    $row = S::call($w['admin'], 'POST', S::u($w, 'usps'), [
        'title' => "A & B\x00 <3",
        'explainer' => "<img src=x onerror=alert(1)>\r\nline two\x07\x1F\ttab",
    ])->assertCreated()->json('data.attributes');

    expect($row['title'])->toBe('A & B <3')
        ->and($row['explainer'])->toBe("<img src=x onerror=alert(1)>\nline two\ttab");
    expect(WaveUsp::query()->firstOrFail()->explainer)->toBe("<img src=x onerror=alert(1)>\nline two\ttab");
});

it('updates only the fields sent (per-field last write wins), and writes no audit row when nothing changed', function (): void {
    $w = S::world();
    $ulid = makeUsps($w, 1)[0];
    $before = AuditLog::query()->where('action', 'wave_usp.updated')->count();

    // Two people, two different fields: both changes survive.
    S::call($w['admin'], 'PATCH', S::u($w, "usps/{$ulid}"), ['title' => 'New title'])->assertOk();
    S::call($w['manager'], 'PATCH', S::u($w, "usps/{$ulid}"), ['explainer' => 'New explainer'])->assertOk();
    $usp = WaveUsp::query()->firstOrFail();
    expect($usp->title)->toBe('New title')->and($usp->explainer)->toBe('New explainer')->and($usp->creative_angle)->toBe('Value perception');

    // The same field twice: the later write wins.
    S::call($w['admin'], 'PATCH', S::u($w, "usps/{$ulid}"), ['title' => 'First'])->assertOk();
    S::call($w['staff'], 'PATCH', S::u($w, "usps/{$ulid}"), ['title' => 'Second'])->assertOk();
    expect(WaveUsp::query()->firstOrFail()->title)->toBe('Second');

    // A field can be cleared; the title cannot.
    S::call($w['admin'], 'PATCH', S::u($w, "usps/{$ulid}"), ['copy_seed' => null])->assertOk()->assertJsonPath('data.attributes.copy_seed', null);
    S::call($w['admin'], 'PATCH', S::u($w, "usps/{$ulid}"), ['title' => null])->assertUnprocessable();
    S::call($w['admin'], 'PATCH', S::u($w, "usps/{$ulid}"), ['title' => ''])->assertUnprocessable();

    // An empty body and a same-value body change nothing and write no audit row.
    $count = AuditLog::query()->where('action', 'wave_usp.updated')->count();
    S::call($w['admin'], 'PATCH', S::u($w, "usps/{$ulid}"), [])->assertOk();
    S::call($w['admin'], 'PATCH', S::u($w, "usps/{$ulid}"), ['title' => 'Second'])->assertOk();
    expect(AuditLog::query()->where('action', 'wave_usp.updated')->count())->toBe($count)->and($count)->toBeGreaterThan($before);
});

it('refuses a USP of another wave, another agency or an unknown ulid with 404 (cards are found through the wave)', function (): void {
    $w = S::world();
    $mine = makeUsps($w, 1)[0];
    $theirs = WaveUsp::factory()->create(['wave_id' => $w['otherWave']->id, 'agency_id' => $w['otherAgency']->id]);
    $sibling = WaveUsp::factory()->create(['wave_id' => Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->create()->id, 'agency_id' => $w['agency']->id]);

    foreach ([$theirs->ulid, $sibling->ulid, '01HZZZZZZZZZZZZZZZZZZZZZZZ'] as $ulid) {
        S::call($w['admin'], 'PATCH', S::u($w, "usps/{$ulid}"), ['title' => 'Hijack'])->assertNotFound();
        S::call($w['admin'], 'DELETE', S::u($w, "usps/{$ulid}"))->assertNotFound();
    }
    expect(WaveUsp::withoutGlobalScopes()->whereKey($theirs->id)->value('title'))->toBe($theirs->title)
        ->and($mine)->toBeString();
});

it('O1 to O4: reorders from the full ordered list, positions are 1 to n, colours stay with the cards', function (): void {
    $w = S::world();
    $ulids = makeUsps($w, 4);
    $colors = function (): array {
        $map = WaveUsp::query()->pluck('color_key', 'ulid')->map(fn ($c) => $c->value)->all();
        ksort($map);

        return $map;
    };
    $colorsBefore = $colors();

    $order = [$ulids[3], $ulids[0], $ulids[2], $ulids[1]];
    $res = S::call($w['admin'], 'PUT', S::u($w, 'usps/order'), ['order' => $order])->assertOk();
    expect(array_column($res->json('data'), 'id'))->toBe($order)
        ->and(array_column(array_column($res->json('data'), 'attributes'), 'position'))->toBe([1, 2, 3, 4])
        ->and($colors())->toBe($colorsBefore);
    expect(S::call($w['admin'], 'GET', S::u($w, 'usps'))->json('data.0.id'))->toBe($ulids[3]);

    expect(AuditLog::query()->where('action', 'wave_usp.reordered')->count())->toBe(1);
    // The same order again changes nothing and writes no second row.
    S::call($w['admin'], 'PUT', S::u($w, 'usps/order'), ['order' => $order])->assertOk();
    expect(AuditLog::query()->where('action', 'wave_usp.reordered')->count())->toBe(1);
});

it('O2, O3: a list that is not exactly the wave\'s USPs is 422 usp.order_mismatch and nothing moves', function (): void {
    $w = S::world();
    $ulids = makeUsps($w, 3);
    $foreign = WaveUsp::factory()->create(['wave_id' => $w['otherWave']->id, 'agency_id' => $w['otherAgency']->id]);
    $before = WaveUsp::query()->orderBy('position')->pluck('ulid')->all();

    foreach ([
        [$ulids[0], $ulids[1]],                        // missing one (a stale drag after another user added a card)
        [$ulids[0], $ulids[1], $ulids[1]],             // duplicate
        [$ulids[0], $ulids[1], $foreign->ulid],        // foreign ulid
        [$ulids[0], $ulids[1], $ulids[2], '01HZZZZZZZZZZZZZZZZZZZZZZZ'],   // extra
    ] as $order) {
        S::call($w['admin'], 'PUT', S::u($w, 'usps/order'), ['order' => $order])
            ->assertUnprocessable()->assertJsonPath('errors.0.code', 'usp.order_mismatch');
    }
    S::call($w['admin'], 'PUT', S::u($w, 'usps/order'), ['order' => 'nope'])->assertUnprocessable();
    expect(WaveUsp::query()->orderBy('position')->pluck('ulid')->all())->toBe($before);
});

it('C1: deleting a USP no angle uses is allowed for every role and renumbers 1 to n; colours of the others do not change', function (): void {
    $w = S::world();
    $ulids = makeUsps($w, 4);

    S::call($w['staff'], 'DELETE', S::u($w, "usps/{$ulids[1]}"))->assertNoContent();
    $rest = WaveUsp::query()->orderBy('position')->get();
    expect($rest->pluck('position')->all())->toBe([1, 2, 3])
        ->and($rest->map(fn ($u) => $u->color_key->value)->all())->toBe(['violet', 'blue', 'amber'])
        ->and(AuditLog::query()->where('action', 'wave_usp.deleted')->count())->toBe(1);
});

/** One USP used by two angles of two personas (and a second USP nobody uses). */
function inUseWorld(): array
{
    $w = S::world();
    [$usp1, $usp2] = array_map(fn (string $u) => WaveUsp::query()->where('ulid', $u)->firstOrFail(), makeUsps($w, 2));
    $p1 = WavePersona::factory()->create(['wave_id' => $w['wave']->id, 'agency_id' => $w['agency']->id, 'market_id' => $w['mkid']['SK'], 'title' => 'The Office Lunch Organiser']);
    $p2 = WavePersona::factory()->create(['wave_id' => $w['wave']->id, 'agency_id' => $w['agency']->id, 'market_id' => $w['mkid']['RO']]);
    foreach ([$p1, $p2] as $p) {
        WavePersonaAngle::factory()->create(['persona_id' => $p->id, 'usp_id' => $usp1->id, 'wave_id' => $w['wave']->id, 'agency_id' => $w['agency']->id]);
    }

    return $w + ['usp1' => $usp1, 'usp2' => $usp2, 'p1' => $p1, 'p2' => $p2];
}

it('C1: deleting an in-use USP without confirmation is 409 usp.in_use with the affected list; nothing is deleted', function (): void {
    $w = inUseWorld();

    $res = S::call($w['admin'], 'DELETE', S::u($w, "usps/{$w['usp1']->ulid}"))->assertStatus(409)->assertJsonPath('errors.0.code', 'usp.in_use');
    expect($res->json('errors.0.meta.count'))->toBe(2)
        ->and(array_column($res->json('errors.0.meta.affected'), 'market'))->toBe([$w['mk']['SK'], $w['mk']['RO']])
        ->and($res->json('errors.0.meta.affected.0.title'))->toBe('The Office Lunch Organiser');
    expect(WaveUsp::query()->count())->toBe(2)->and(WavePersonaAngle::query()->count())->toBe(2);
});

it('C2: with the matching confirm_count the angles and the USP are removed and positions are renumbered', function (): void {
    $w = inUseWorld();

    S::call($w['manager'], 'DELETE', S::u($w, "usps/{$w['usp1']->ulid}?confirm_count=2"))->assertNoContent();
    expect(WaveUsp::query()->count())->toBe(1)
        ->and(WaveUsp::query()->firstOrFail()->position)->toBe(1)
        ->and(WavePersonaAngle::query()->count())->toBe(0)
        ->and(WavePersona::query()->count())->toBe(2);   // the personas stay
    $audit = AuditLog::query()->where('action', 'wave_usp.deleted')->firstOrFail();
    expect($audit->metadata)->toMatchArray(['angles_removed' => 2]);
});

it('C3: with a stale count it is 409 again with the fresh list; nothing is deleted', function (): void {
    $w = inUseWorld();

    foreach ([1, 3, 0] as $stale) {
        S::call($w['admin'], 'DELETE', S::u($w, "usps/{$w['usp1']->ulid}?confirm_count={$stale}"))
            ->assertStatus(409)->assertJsonPath('errors.0.meta.count', 2);
    }
    expect(WaveUsp::query()->count())->toBe(2)->and(WavePersonaAngle::query()->count())->toBe(2);
});

it('C4: the delete is atomic: a failure after the angles are removed leaves everything in place', function (): void {
    $w = inUseWorld();

    // Fail the audit write (the last step of the transaction).
    AuditLog::creating(static function (): never {
        throw new RuntimeException('boom');
    });
    try {
        S::call($w['admin'], 'DELETE', S::u($w, "usps/{$w['usp1']->ulid}?confirm_count=2"));
    } catch (Throwable) {
        // the exception handler may turn it into a 500 or rethrow
    }
    AuditLog::flushEventListeners();

    expect(WaveUsp::query()->count())->toBe(2)->and(WavePersonaAngle::query()->count())->toBe(2);
});

it('R2, R3: staff may delete an unused USP but NOT an in-use one (403, whatever the count)', function (): void {
    $w = inUseWorld();

    S::call($w['staff'], 'DELETE', S::u($w, "usps/{$w['usp1']->ulid}"))->assertForbidden();
    S::call($w['staff'], 'DELETE', S::u($w, "usps/{$w['usp1']->ulid}?confirm_count=2"))->assertForbidden();
    expect(WaveUsp::query()->count())->toBe(2);
    S::call($w['staff'], 'DELETE', S::u($w, "usps/{$w['usp2']->ulid}"))->assertNoContent();
    expect(WaveUsp::query()->count())->toBe(1);
});

it('R1, R4: staff create, edit, reorder and set the title; manager and admin delete an in-use USP with the count', function (): void {
    $w = S::world();
    $a = S::call($w['staff'], 'POST', S::u($w, 'usps'), uspBody(['title' => 'One']))->assertCreated()->json('data.id');
    $b = S::call($w['staff'], 'POST', S::u($w, 'usps'), uspBody(['title' => 'Two']))->assertCreated()->json('data.id');
    S::call($w['staff'], 'PATCH', S::u($w, "usps/{$a}"), ['title' => 'One!'])->assertOk();
    S::call($w['staff'], 'PUT', S::u($w, 'usps/order'), ['order' => [$b, $a]])->assertOk();
    S::call($w['staff'], 'PATCH', S::u($w, 'audience-title'), ['usp_audience_title' => 'Eaters'])->assertOk();

    $angle = WavePersonaAngle::factory()->create([
        'persona_id' => WavePersona::factory()->create(['wave_id' => $w['wave']->id, 'agency_id' => $w['agency']->id])->id,
        'usp_id' => WaveUsp::query()->where('ulid', $a)->firstOrFail()->id, 'wave_id' => $w['wave']->id, 'agency_id' => $w['agency']->id,
    ]);
    expect($angle->exists)->toBeTrue();
    S::call($w['admin'], 'DELETE', S::u($w, "usps/{$a}?confirm_count=1"))->assertNoContent();
});

it('audience title: set, clear, limit of 80, cleaned, and an audit row only when it changed', function (): void {
    $w = S::world();
    $url = S::u($w, 'audience-title');

    S::call($w['admin'], 'PATCH', $url, ['usp_audience_title' => '  Bolt Food Eaters — 6 core USPs '])->assertOk()
        ->assertJsonPath('data.attributes.audience_title', 'Bolt Food Eaters — 6 core USPs');
    expect(AuditLog::query()->where('action', 'wave.audience_title_updated')->count())->toBe(1);

    S::call($w['admin'], 'PATCH', $url, ['usp_audience_title' => 'Bolt Food Eaters — 6 core USPs'])->assertOk();
    expect(AuditLog::query()->where('action', 'wave.audience_title_updated')->count())->toBe(1);

    S::call($w['admin'], 'PATCH', $url, ['usp_audience_title' => str_repeat('a', 81)])->assertUnprocessable();
    S::call($w['admin'], 'PATCH', $url, ['usp_audience_title' => str_repeat('a', 80)])->assertOk();
    S::call($w['admin'], 'PATCH', $url, ['usp_audience_title' => '   '])->assertOk()->assertJsonPath('data.attributes.audience_title', null);
    S::call($w['admin'], 'PATCH', $url, [])->assertUnprocessable();   // the key must be present
});

it('summary: counts, the title, one entry per market of the wave (with 0), and the limits', function (): void {
    $w = S::world();
    makeUsps($w, 3);
    for ($i = 0; $i < 2; $i++) {
        WavePersona::factory()->create(['wave_id' => $w['wave']->id, 'agency_id' => $w['agency']->id, 'market_id' => $w['mkid']['SK']]);
    }
    S::call($w['admin'], 'PATCH', S::u($w, 'audience-title'), ['usp_audience_title' => 'Eaters'])->assertOk();

    $a = S::call($w['staff'], 'GET', S::u($w))->assertOk()->json('data.attributes');
    expect($a['usp_count'])->toBe(3)->and($a['audience_title'])->toBe('Eaters')->and($a['persona_count'])->toBe(2)
        ->and($a['markets'])->toBe([['market' => $w['mk']['SK'], 'name' => 'Slovakia', 'code' => 'SK', 'countries' => ['SK'], 'persona_count' => 2], ['market' => $w['mk']['RO'], 'name' => 'Romania', 'code' => 'RO', 'countries' => ['RO'], 'persona_count' => 0], ['market' => $w['mk']['CZ'], 'name' => 'Czechia', 'code' => 'CZ', 'countries' => ['CZ'], 'persona_count' => 0]])
        ->and($a['limits']['usp_max'])->toBe(6)->and($a['limits']['persona_max'])->toBe(8)->and($a['limits']['usp_title'])->toBe(40);
});

it('A1: each USP write makes exactly one audit row with decided keys and never the card text', function (): void {
    $w = S::world();
    $ulid = S::call($w['admin'], 'POST', S::u($w, 'usps'), uspBody(['title' => 'SECRET-TITLE', 'explainer' => 'SECRET-EXPLAINER']))->assertCreated()->json('data.id');
    S::call($w['admin'], 'PATCH', S::u($w, "usps/{$ulid}"), ['explainer' => 'SECRET-NEW', 'copy_seed' => 'SECRET-SEED'])->assertOk();
    S::call($w['admin'], 'DELETE', S::u($w, "usps/{$ulid}"))->assertNoContent();

    $rows = AuditLog::query()->whereIn('action', ['wave_usp.created', 'wave_usp.updated', 'wave_usp.deleted'])->orderBy('id')->get();
    $meta = array_values($rows->pluck('metadata')->all());
    expect($rows->pluck('action')->map(fn ($a) => is_string($a) ? $a : $a->value)->all())->toBe(['wave_usp.created', 'wave_usp.updated', 'wave_usp.deleted'])
        ->and($meta[0])->toBe(['wave' => $w['wave']->ulid, 'usp' => $ulid, 'color_key' => 'violet'])
        ->and($meta[1])->toMatchArray(['changed' => ['explainer', 'copy_seed']])
        ->and($meta[2])->toMatchArray(['angles_removed' => 0]);
    expect(json_encode($rows->map(fn ($r) => [$r->metadata, $r->before, $r->after])->all()))->not->toContain('SECRET');
});

it('T1, T2: another agency, another brand and a missing wave are 404 on every USP endpoint', function (): void {
    $w = S::world();
    $mine = makeUsps($w, 1)[0];

    // Agency B's admin on agency A's address.
    foreach ([['GET', ''], ['GET', 'usps'], ['POST', 'usps'], ['PUT', 'usps/order'], ['PATCH', "usps/{$mine}"], ['DELETE', "usps/{$mine}"], ['PATCH', 'audience-title']] as [$method, $suffix]) {
        S::call($w['otherAdmin'], $method, S::u($w, $suffix), ['title' => 'x', 'order' => [], 'usp_audience_title' => 'x'])->assertNotFound();
    }
    // Agency A's admin with a brand of agency B in the path, and with a wave under the wrong brand.
    S::call($w['admin'], 'GET', S::url($w['agency'], $w['otherBrand'], $w['wave'], 'usps'))->assertNotFound();
    $siblingBrand = Brand::factory()->forAgency($w['agency']->id)->createOne();
    S::call($w['admin'], 'GET', S::url($w['agency'], $siblingBrand, $w['wave'], 'usps'))->assertNotFound();
    S::call($w['admin'], 'GET', S::url($w['agency'], $w['brand'], $w['otherWave'], 'usps'))->assertNotFound();
    // A soft-deleted wave is a 404.
    $w['wave']->delete();
    S::call($w['admin'], 'GET', S::u($w, 'usps'))->assertNotFound();
    expect(WaveUsp::withoutGlobalScopes()->count())->toBe(1);
});

it('an archived brand is read-only: every write is 422 wave.brand_archived, reads still work', function (): void {
    $w = S::world();
    $ulid = makeUsps($w, 1)[0];
    $w['brand']->forceFill(['status' => BrandStatus::Archived])->save();

    S::call($w['admin'], 'GET', S::u($w, 'usps'))->assertOk();
    S::call($w['admin'], 'POST', S::u($w, 'usps'), uspBody())->assertUnprocessable()->assertJsonPath('errors.0.code', 'wave.brand_archived');
    S::call($w['admin'], 'PATCH', S::u($w, "usps/{$ulid}"), ['title' => 'x'])->assertUnprocessable()->assertJsonPath('errors.0.code', 'wave.brand_archived');
    S::call($w['admin'], 'DELETE', S::u($w, "usps/{$ulid}"))->assertUnprocessable();
    S::call($w['admin'], 'PATCH', S::u($w, 'audience-title'), ['usp_audience_title' => 'x'])->assertUnprocessable();
    expect(WaveUsp::query()->count())->toBe(1);
});

it('L5: every USP write takes the wave row lock first (the for update SQL) [PostgreSQL]', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('SQLite ignores row locks; the lock SQL is asserted on PostgreSQL.');
    }
    $w = S::world();
    $sql = [];
    DB::listen(function ($q) use (&$sql): void {
        $sql[] = strtolower($q->sql);
    });

    $ulid = makeUsps($w, 1)[0];
    $isWaveLock = fn (string $s): bool => str_contains($s, 'from "waves"') && str_contains($s, 'for update');
    expect(collect($sql)->contains($isWaveLock))->toBeTrue();

    foreach ([
        ['PATCH', "usps/{$ulid}", ['title' => 'Changed']],
        ['PUT', 'usps/order', ['order' => [$ulid]]],
        ['PATCH', 'audience-title', ['usp_audience_title' => 'T']],
        ['DELETE', "usps/{$ulid}", []],
    ] as [$method, $suffix, $body]) {
        $sql = [];
        S::call($w['admin'], $method, S::u($w, $suffix), $body)->assertSuccessful();
        expect(collect($sql)->contains($isWaveLock))->toBeTrue("{$method} {$suffix} must lock the wave row");
    }
});
