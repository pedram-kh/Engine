<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Brands\Enums\BrandStatus;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Enums\MatrixLevel;
use App\Modules\Strategy\Features\StrategyEnabled;
use App\Modules\Strategy\Models\WaveConcept;
use App\Modules\Strategy\Models\WaveConceptDecision;
use App\Modules\Strategy\Models\WaveConceptUsp;
use App\Modules\Strategy\Models\WaveUsp;
use App\Modules\Strategy\Services\ConceptService;
use App\Modules\Waves\Models\Wave;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Pennant\Feature;
use Tests\Support\ConceptFixtures as C;
use Tests\Support\StrategyFixtures as S;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * PL-2.3 — concepts (plan sections 1, 3). Gates: flags, tenancy, roles (C9), create and chips (C3), edit and the reset
 * rules (Q6), persona change (Q11), the 30 ceiling (Q5), delete (Q4), decisions (C5, Q13), the persona, USP and market
 * rules (Q4), constant queries, the stepper counts, and that no audit row carries a card text or a client's words.
 */

beforeEach(fn () => Storage::fake('media'));

it('the flags: strategy_enabled OFF and waves_enabled OFF are both 404 not_found', function (): void {
    $w = S::world();
    $p = C::persona($w);
    Feature::deactivate(StrategyEnabled::NAME);
    S::call($w['admin'], 'GET', S::u($w, "concepts?market={$w['mk']['SK']}"))->assertNotFound()->assertJsonPath('errors.0.code', 'not_found');
    S::call($w['admin'], 'POST', S::u($w, 'concepts'), ['market' => $w['mk']['SK'], 'persona' => $p->ulid, 'title' => 'x'])->assertNotFound();
    expect(WaveConcept::query()->count())->toBe(0);
});

it('creates a concept with chips from the matrix: Priority first, then Support, each by USP position; Low gives none', function (): void {
    $w = S::world();
    $p = C::persona($w);
    $u1 = C::usp($w, 'One');
    $u2 = C::usp($w, 'Two');
    $u3 = C::usp($w, 'Three');
    $u4 = C::usp($w, 'Four');
    C::cell($p, $u3, MatrixLevel::Priority);
    C::cell($p, $u1, MatrixLevel::Support);
    C::cell($p, $u2, MatrixLevel::Priority);
    C::cell($p, $u4, MatrixLevel::Low);

    $row = C::create($w, $p, ['funnel_stage' => 'mofu', 'funnel_note' => 'consideration', 'key_points' => ['a', 'b']])->assertCreated()->json('data');

    expect($row['type'])->toBe('wave_concept')
        ->and($row['attributes']['number'])->toBe(1)
        ->and($row['attributes']['status'])->toBe('awaiting')
        ->and($row['attributes']['funnel_stage'])->toBe('mofu')
        ->and($row['attributes']['key_points'])->toBe(['a', 'b'])
        ->and(array_column($row['attributes']['usps'], 'title'))->toBe(['Two', 'Three', 'One'])
        ->and($row['attributes']['persona']['id'])->toBe($p->ulid)
        ->and($row['attributes'])->not->toHaveKeys(['id', 'agency_id', 'wave_id', 'persona_id'])
        ->and($row['attributes']['decision'])->toBeNull();

    $second = C::create($w, C::persona($w))->assertCreated()->json('data');
    expect($second['attributes']['number'])->toBe(2)->and($second['attributes']['usps'])->toBe([]);
});

it('a persona of another market, another wave or another agency is refused', function (): void {
    $w = S::world();
    $ro = C::persona($w, 'RO');
    C::create($w, $ro, ['market' => $w['mk']['SK']])->assertUnprocessable()->assertJsonPath('errors.0.code', 'concept.persona_invalid');
    C::create($w, C::persona($w), ['persona' => '01HZZZZZZZZZZZZZZZZZZZZZZZ'])->assertUnprocessable();
    C::create($w, C::persona($w), ['market' => S::NO_MARKET])->assertUnprocessable();
    expect(WaveConcept::query()->count())->toBe(0);
});

it('roles: staff create, edit and duplicate; a creator is refused; the decision is admin and manager only', function (): void {
    $w = S::world();
    $p = C::persona($w);
    $id = C::make($w, $p);

    S::call($w['staff'], 'PATCH', S::u($w, "concepts/{$id}"), ['title' => 'Renamed'])->assertOk();
    S::call($w['staff'], 'POST', S::u($w, "concepts/{$id}/duplicate"))->assertCreated();
    S::call($w['staff'], 'GET', S::u($w, "concepts?market={$w['mk']['SK']}"))->assertOk();

    C::decide($w, $id, 'approved', $w['staff'])->assertForbidden();
    expect(WaveConceptDecision::query()->count())->toBe(0);
    C::decide($w, $id, 'approved', $w['manager'])->assertOk();
    C::decide($w, $id, 'changes_requested', $w['admin'])->assertOk();

    $creator = User::factory()->creator()->createOne();
    expect(S::call($creator, 'GET', S::u($w, "concepts?market={$w['mk']['SK']}"))->status())->toBeIn([403, 404]);
    S::call($w['otherAdmin'], 'GET', S::u($w, "concepts?market={$w['mk']['SK']}"))->assertStatus(404);
});

it('tenancy: a concept of another wave, brand or agency is a 404', function (): void {
    $w = S::world();
    $id = C::make($w, C::persona($w));
    $other = Wave::factory()->forBrand($w['agency']->id, $w['brand']->id)->create();
    $url = S::url($w['agency'], $w['brand'], $other, "concepts/{$id}");

    S::call($w['admin'], 'PATCH', $url, ['title' => 'x'])->assertNotFound();
    S::call($w['admin'], 'DELETE', $url)->assertNotFound();
    S::call($w['admin'], 'POST', $url.'/decision', ['status' => 'approved', 'client_name' => 'A'])->assertNotFound();
    S::call($w['otherAdmin'], 'PATCH', S::u($w, "concepts/{$id}"), ['title' => 'x'])->assertNotFound();
    S::call($w['otherAdmin'], 'PATCH', S::url($w['otherAgency'], $w['otherBrand'], $w['otherWave'], "concepts/{$id}"), ['title' => 'x'])->assertNotFound();
    expect(DB::table('wave_concepts')->where('ulid', $id)->value('title'))->toBe('One Order, No Drama');
});

it('lists a market in position order; the market is required and must belong to the wave', function (): void {
    $w = S::world();
    $p = C::persona($w);
    C::make($w, $p, ['title' => 'First']);
    C::make($w, $p, ['title' => 'Second']);
    C::make($w, C::persona($w, 'RO'), ['title' => 'Romanian']);

    $rows = S::call($w['admin'], 'GET', S::u($w, "concepts?market={$w['mk']['SK']}"))->assertOk()->json();
    expect(array_column(array_column($rows['data'], 'attributes'), 'title'))->toBe(['First', 'Second'])
        ->and($rows['meta']['market'])->toMatchArray(['market' => $w['mk']['SK'], 'total' => 2, 'under_minimum' => true]);
    S::call($w['admin'], 'GET', S::u($w, 'concepts'))->assertUnprocessable()->assertJsonPath('errors.0.code', 'concept.market_required');
    S::call($w['admin'], 'GET', S::u($w, 'concepts?market=DE'))->assertUnprocessable();
});

it('the list is a constant number of queries however many concepts it holds', function (): void {
    $w = S::world();
    $p = C::persona($w);
    $usp = C::usp($w);
    C::cell($p, $usp, MatrixLevel::Priority);
    $count = function () use ($w): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        S::call($w['admin'], 'GET', S::u($w, "concepts?market={$w['mk']['SK']}"))->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };
    $first = C::make($w, $p);
    C::decide($w, $first, 'approved')->assertOk();
    S::call($w['staff'], 'POST', S::u($w, "concepts/{$first}/links"), ['url' => 'https://example.com/a', 'title' => 'A'])->assertCreated();
    $count();   // warm-up: feature flags and the like are resolved once per process
    $one = $count();
    for ($i = 0; $i < 8; $i++) {
        $id = C::make($w, $p);
        C::decide($w, $id, 'approved')->assertOk();
        S::call($w['admin'], 'POST', S::u($w, "concepts/{$id}/links"), ['url' => 'https://example.com/a', 'title' => 'A'])->assertCreated();
    }
    expect($count())->toBe($one);
});

it('edits only what is sent; a no-op writes nothing, audits nothing and resets nothing', function (): void {
    $w = S::world();
    $p = C::persona($w);
    $id = C::make($w, $p, ['hook' => 'Old hook']);
    C::decide($w, $id, 'approved')->assertOk();
    $audits = AuditLog::query()->count();

    $same = S::call($w['staff'], 'PATCH', S::u($w, "concepts/{$id}"), ['title' => 'One Order, No Drama', 'hook' => 'Old hook'])->assertOk()->json('data.attributes');
    expect($same['status'])->toBe('approved')->and($same['reset_to_awaiting'])->toBeFalse()->and(AuditLog::query()->count())->toBe($audits);

    $changed = S::call($w['staff'], 'PATCH', S::u($w, "concepts/{$id}"), ['hook' => 'New hook', 'cta' => 'Order now'])->assertOk()->json('data.attributes');
    expect($changed['hook'])->toBe('New hook')->and($changed['cta'])->toBe('Order now')->and($changed['title'])->toBe('One Order, No Drama');
    $meta = C::lastAudit('wave_concept.updated');
    expect($meta['fields_changed'] ?? null)->toBe(['hook', 'cta'])->and($meta['reset_to_awaiting'] ?? null)->toBeTrue();
});

it('Q6: editing an Approved concept sends it to Awaiting and keeps the decision; Changes requested stays red; Awaiting stays', function (): void {
    $w = S::world();
    $p = C::persona($w);
    $approved = C::make($w, $p);
    $red = C::make($w, $p);
    $awaiting = C::make($w, $p);
    C::decide($w, $approved, 'approved')->assertOk();
    C::decide($w, $red, 'changes_requested')->assertOk();

    expect(S::call($w['staff'], 'PATCH', S::u($w, "concepts/{$approved}"), ['payoff' => 'x'])->json('data.attributes'))
        ->toMatchArray(['status' => 'awaiting', 'reset_to_awaiting' => true]);
    expect(S::call($w['staff'], 'PATCH', S::u($w, "concepts/{$red}"), ['payoff' => 'x'])->json('data.attributes'))
        ->toMatchArray(['status' => 'changes_requested', 'reset_to_awaiting' => false]);
    expect(S::call($w['staff'], 'PATCH', S::u($w, "concepts/{$awaiting}"), ['payoff' => 'x'])->json('data.attributes'))
        ->toMatchArray(['status' => 'awaiting', 'reset_to_awaiting' => false]);

    expect(WaveConceptDecision::query()->count())->toBe(2)
        ->and(S::call($w['admin'], 'GET', S::u($w, "concepts/{$approved}/decisions"))->json('data'))->toHaveCount(1);
});

it('Q11: changing the persona needs usp_mode; keep leaves the chips, refill rebuilds them from the new persona', function (): void {
    $w = S::world();
    $a = C::persona($w);
    $b = C::persona($w);
    $x = C::usp($w, 'X');
    $y = C::usp($w, 'Y');
    C::cell($a, $x, MatrixLevel::Priority);
    C::cell($b, $y, MatrixLevel::Priority);
    $id = C::make($w, $a);

    S::call($w['staff'], 'PATCH', S::u($w, "concepts/{$id}"), ['persona' => $b->ulid])->assertUnprocessable()->assertJsonPath('errors.0.code', 'concept.usp_mode_required');
    expect(WaveConcept::query()->firstOrFail()->persona_id)->toBe($a->id);

    $kept = S::call($w['staff'], 'PATCH', S::u($w, "concepts/{$id}"), ['persona' => $b->ulid, 'usp_mode' => 'keep'])->assertOk()->json('data.attributes');
    expect($kept['persona']['id'])->toBe($b->ulid)->and(array_column($kept['usps'], 'title'))->toBe(['X']);

    $refilled = S::call($w['staff'], 'PATCH', S::u($w, "concepts/{$id}"), ['persona' => $a->ulid, 'usp_mode' => 'refill'])->assertOk()->json('data.attributes');
    expect(array_column($refilled['usps'], 'title'))->toBe(['X']);
    $back = S::call($w['staff'], 'PATCH', S::u($w, "concepts/{$id}"), ['persona' => $b->ulid, 'usp_mode' => 'refill'])->assertOk()->json('data.attributes');
    expect(array_column($back['usps'], 'title'))->toBe(['Y']);

    // The same persona again needs no mode.
    S::call($w['staff'], 'PATCH', S::u($w, "concepts/{$id}"), ['persona' => $b->ulid])->assertOk();
});

it('the chip set can be replaced by hand with any USP of the wave, in the order sent; another wave USP is refused', function (): void {
    $w = S::world();
    $p = C::persona($w);
    $x = C::usp($w, 'X');
    $y = C::usp($w, 'Y');
    $id = C::make($w, $p);

    $row = S::call($w['staff'], 'PATCH', S::u($w, "concepts/{$id}"), ['usps' => [$y->ulid, $x->ulid]])->assertOk()->json('data.attributes');
    expect(array_column($row['usps'], 'title'))->toBe(['Y', 'X']);

    $otherUsp = WaveUsp::factory()->create(['wave_id' => $w['otherWave']->id, 'agency_id' => $w['otherAgency']->id]);
    S::call($w['staff'], 'PATCH', S::u($w, "concepts/{$id}"), ['usps' => [$otherUsp->ulid]])->assertUnprocessable();
    expect(WaveConceptUsp::query()->count())->toBe(2);
});

it('Q5: at most 30 concepts per market, for create and for duplicate; another market is unaffected', function (): void {
    $w = S::world();
    $p = C::persona($w);
    for ($i = 1; $i <= 30; $i++) {
        WaveConcept::factory()->create(['persona_id' => $p->id]);
    }
    C::create($w, $p)->assertUnprocessable()->assertJsonPath('errors.0.code', 'concept.market_full');
    $first = (string) WaveConcept::query()->orderBy('id')->value('ulid');
    S::call($w['staff'], 'POST', S::u($w, "concepts/{$first}/duplicate"))->assertUnprocessable()->assertJsonPath('errors.0.code', 'concept.market_full');
    expect(WaveConcept::query()->count())->toBe(30);
    C::create($w, C::persona($w, 'RO'))->assertCreated();
});

it('Q4: staff delete an Awaiting concept; an Approved or Changes requested one needs admin or manager; positions close up', function (): void {
    $w = S::world();
    $p = C::persona($w);
    $one = C::make($w, $p);
    $two = C::make($w, $p);
    $three = C::make($w, $p);
    C::decide($w, $two, 'approved')->assertOk();
    C::decide($w, $three, 'changes_requested')->assertOk();

    S::call($w['staff'], 'DELETE', S::u($w, "concepts/{$two}"))->assertForbidden();
    S::call($w['staff'], 'DELETE', S::u($w, "concepts/{$three}"))->assertForbidden();
    expect(WaveConcept::query()->count())->toBe(3);

    S::call($w['staff'], 'DELETE', S::u($w, "concepts/{$one}"))->assertNoContent();
    S::call($w['manager'], 'DELETE', S::u($w, "concepts/{$two}"))->assertNoContent();
    S::call($w['admin'], 'DELETE', S::u($w, "concepts/{$three}"))->assertNoContent();
    expect(WaveConcept::query()->count())->toBe(0)->and(WaveConceptDecision::query()->count())->toBe(0);

    $a = C::make($w, $p);
    $b = C::make($w, $p);
    S::call($w['staff'], 'DELETE', S::u($w, "concepts/{$a}"))->assertNoContent();
    expect(WaveConcept::query()->firstOrFail()->position)->toBe(1)->and(WaveConcept::query()->firstOrFail()->ulid)->toBe($b);
    expect(array_keys(C::lastAudit('wave_concept.deleted')))->toContain('chips_removed', 'images_removed', 'links_removed', 'decisions_removed');
});

it('duplicate: a new Awaiting concept at the end, same persona, fields and chips, no decision', function (): void {
    $w = S::world();
    $p = C::persona($w);
    $usp = C::usp($w);
    C::cell($p, $usp, MatrixLevel::Priority);
    $id = C::make($w, $p, ['hook' => 'H', 'key_points' => ['k'], 'funnel_stage' => 'bofu']);
    S::call($w['staff'], 'POST', S::u($w, "concepts/{$id}/links"), ['url' => 'https://example.com/x', 'title' => 'X'])->assertCreated();
    C::decide($w, $id, 'approved')->assertOk();

    $copy = S::call($w['staff'], 'POST', S::u($w, "concepts/{$id}/duplicate"))->assertCreated()->json('data');
    $a = $copy['attributes'];
    expect($copy['id'])->not->toBe($id)
        ->and($a['title'])->toBe('One Order, No Drama (copy)')
        ->and($a['status'])->toBe('awaiting')->and($a['decision'])->toBeNull()
        ->and($a['number'])->toBe(2)->and($a['hook'])->toBe('H')->and($a['key_points'])->toBe(['k'])->and($a['funnel_stage'])->toBe('bofu')
        ->and(array_column($a['usps'], 'title'))->toBe([$usp->title])
        ->and($a['links'])->toHaveCount(1);
    $meta = C::lastAudit('wave_concept.duplicated');
    expect($meta['from_concept'] ?? null)->toBe($id)->and($meta['concept'] ?? null)->toBe($copy['id']);
});

it('decisions: the name is required; approved takes a note and no comment; changes requested needs a comment; each is kept', function (): void {
    $w = S::world();
    $id = C::make($w, C::persona($w));
    $url = S::u($w, "concepts/{$id}/decision");

    S::call($w['manager'], 'POST', $url, ['status' => 'approved'])->assertUnprocessable();
    S::call($w['manager'], 'POST', $url, ['status' => 'approved', 'client_name' => '   '])->assertUnprocessable();
    S::call($w['manager'], 'POST', $url, ['status' => 'approved', 'client_name' => 'Anna', 'comment' => 'x'])->assertUnprocessable()->assertJsonPath('errors.0.code', 'concept.decision_invalid');
    S::call($w['manager'], 'POST', $url, ['status' => 'changes_requested', 'client_name' => 'Anna'])->assertUnprocessable()->assertJsonPath('errors.0.code', 'concept.decision_invalid');
    S::call($w['manager'], 'POST', $url, ['status' => 'awaiting', 'client_name' => 'Anna'])->assertUnprocessable();
    S::call($w['manager'], 'POST', $url, ['status' => 'approved', 'client_name' => str_repeat('a', 81)])->assertUnprocessable();
    S::call($w['manager'], 'POST', $url, ['status' => 'changes_requested', 'client_name' => 'A', 'comment' => str_repeat('a', 1001)])->assertUnprocessable();
    expect(WaveConceptDecision::query()->count())->toBe(0);

    $ok = S::call($w['manager'], 'POST', $url, ['status' => 'approved', 'client_name' => 'Anna K.', 'note' => 'Love it'])->assertOk()->json('data.attributes');
    expect($ok['status'])->toBe('approved')->and($ok['decision'])->toMatchArray(['status' => 'approved', 'client_name' => 'Anna K.', 'note' => 'Love it', 'comment' => null, 'source' => 'agency'])
        ->and($ok['decision']['recorded_by']['id'])->toBe($w['manager']->ulid);

    $red = S::call($w['admin'], 'POST', $url, ['status' => 'changes_requested', 'client_name' => 'Boris', 'comment' => 'Shorter hook', 'note' => 'ignored'])->assertOk()->json('data.attributes');
    expect($red['status'])->toBe('changes_requested')->and($red['decision']['client_name'])->toBe('Boris')->and($red['decision']['note'])->toBeNull();

    $history = S::call($w['admin'], 'GET', S::u($w, "concepts/{$id}/decisions"))->assertOk()->json('data');
    expect(array_column($history, 'client_name'))->toBe(['Boris', 'Anna K.']);
});

it('Q13: no undo: a new decision replaces the status and every decision stays in the history', function (): void {
    $w = S::world();
    $id = C::make($w, C::persona($w));
    C::decide($w, $id, 'approved')->assertOk();
    C::decide($w, $id, 'changes_requested')->assertOk();
    C::decide($w, $id, 'approved')->assertOk();
    expect(WaveConcept::query()->firstOrFail()->status->value)->toBe('approved')->and(WaveConceptDecision::query()->count())->toBe(3);
    S::call($w['admin'], 'DELETE', S::u($w, "concepts/{$id}/decisions"))->assertStatus(405);
});

it('audit rows carry ids, counts and flags: never a title, card text, client name, note, comment or URL', function (): void {
    $w = S::world();
    $p = C::persona($w);
    $id = C::make($w, $p, ['title' => 'SECRET-TITLE-QZ', 'hook' => 'SECRET-HOOK-QZ']);
    S::call($w['staff'], 'PATCH', S::u($w, "concepts/{$id}"), ['payoff' => 'SECRET-PAYOFF-QZ'])->assertOk();
    S::call($w['staff'], 'POST', S::u($w, "concepts/{$id}/links"), ['url' => 'https://secret-link-qz.example.com/p', 'title' => 'SECRET-LINKTITLE-QZ'])->assertCreated();
    S::call($w['manager'], 'POST', S::u($w, "concepts/{$id}/decision"), ['status' => 'approved', 'client_name' => 'SECRET-CLIENT-QZ', 'note' => 'SECRET-NOTE-QZ'])->assertOk();
    S::call($w['manager'], 'POST', S::u($w, "concepts/{$id}/decision"), ['status' => 'changes_requested', 'client_name' => 'SECRET-CLIENT-QZ', 'comment' => 'SECRET-COMMENT-QZ'])->assertOk();
    $dup = S::call($w['staff'], 'POST', S::u($w, "concepts/{$id}/duplicate"))->assertCreated()->json('data.id');
    S::call($w['admin'], 'DELETE', S::u($w, "concepts/{$dup}"))->assertNoContent();

    $dump = AuditLog::query()->where('action', 'like', 'wave_concept%')->get()->map(fn (AuditLog $r): string => (string) json_encode([$r->metadata, $r->action]))->implode("\n");
    expect($dump)->not->toContain('SECRET')->and($dump)->not->toContain('secret-link');

    $decided = C::auditMeta('wave_concept.decided');
    expect($decided)->toHaveCount(2)
        ->and(array_keys($decided[0]))->toBe(['wave', 'concept', 'market', 'status', 'has_note', 'has_comment'])
        ->and($decided[0]['has_note'])->toBeTrue()->and($decided[1]['has_comment'])->toBeTrue();
    expect(AuditLog::query()->whereIn('action', ['wave_concept.created', 'wave_concept.updated', 'wave_concept.decided', 'wave_concept.duplicated', 'wave_concept.deleted', 'wave_concept_link.added'])->count())->toBe(7);
});

it('Q4: a persona that concepts use cannot be deleted, for any role; with its concepts gone it can', function (): void {
    $w = S::world();
    $p = C::persona($w);
    $a = C::make($w, $p, ['title' => 'Alpha']);
    $b = C::make($w, $p, ['title' => 'Beta']);
    C::decide($w, $b, 'approved')->assertOk();

    foreach (['admin', 'manager', 'staff'] as $role) {
        $res = S::call($w[$role], 'DELETE', S::u($w, "personas/{$p->ulid}"), ['confirm_count' => 2])->assertUnprocessable();
        expect($res->json('errors.0.code'))->toBe('persona.in_use_by_concepts')
            ->and($res->json('errors.0.meta.count'))->toBe(2)
            ->and(array_column($res->json('errors.0.meta.affected'), 'title'))->toBe(['Alpha', 'Beta'])
            ->and($res->json('errors.0.meta.affected.1.status'))->toBe('approved');
    }
    expect(DB::table('wave_personas')->where('ulid', $p->ulid)->exists())->toBeTrue()->and(WaveConcept::query()->count())->toBe(2);

    S::call($w['admin'], 'DELETE', S::u($w, "concepts/{$a}"))->assertNoContent();
    S::call($w['admin'], 'DELETE', S::u($w, "concepts/{$b}"))->assertNoContent();
    S::call($w['staff'], 'DELETE', S::u($w, "personas/{$p->ulid}"))->assertNoContent();
});

it('Q4: deleting a USP removes its chip, keeps the concepts, resets an approved one, and lists the concepts first', function (): void {
    $w = S::world();
    $p = C::persona($w);
    $x = C::usp($w, 'X');
    $y = C::usp($w, 'Y');
    C::cell($p, $x, MatrixLevel::Priority);
    C::cell($p, $y, MatrixLevel::Support);
    $approved = C::make($w, $p, ['title' => 'Approved one']);
    $awaiting = C::make($w, $p, ['title' => 'Awaiting one']);
    C::decide($w, $approved, 'approved')->assertOk();
    $url = S::u($w, "usps/{$x->ulid}");

    // Staff may not delete a USP that is in use; an admin without the confirmation gets the list, concepts included.
    S::call($w['staff'], 'DELETE', $url, ['confirm_count' => 3])->assertForbidden();
    $list = S::call($w['admin'], 'DELETE', $url)->assertStatus(409)->json('errors.0.meta');
    $concepts = array_values(array_filter($list['affected'], fn (array $i): bool => $i['kind'] === 'concept'));
    expect($concepts)->toHaveCount(2)->and(array_column($concepts, 'title'))->toContain('Approved one', 'Awaiting one')
        ->and($list['count'])->toBe(count($list['affected']));

    S::call($w['admin'], 'DELETE', $url, ['confirm_count' => $list['count']])->assertNoContent();

    expect(WaveConcept::query()->count())->toBe(2)
        ->and(WaveConcept::query()->where('ulid', $approved)->firstOrFail()->status->value)->toBe('awaiting')
        ->and(WaveConceptDecision::query()->count())->toBe(1)
        ->and(WaveConceptUsp::query()->count())->toBe(2);
    $row = S::call($w['admin'], 'GET', S::u($w, "concepts?market={$w['mk']['SK']}"))->json('data.0.attributes');
    expect(array_column($row['usps'], 'title'))->toBe(['Y'])->and(array_column(WaveConceptUsp::query()->orderBy('id')->get()->all(), 'position'))->toBe([1, 1]);
    $audit = C::lastAudit('wave_usp.deleted');
    expect($audit['chips_removed'] ?? null)->toBe(2)->and($audit['concepts_reset'] ?? null)->toBe(1);
});

it('Q4: a market that has concepts cannot be removed from the wave (its personas are refused the same way)', function (): void {
    $w = S::world();
    C::make($w, C::persona($w, 'RO'));

    C::make($w, C::persona($w, 'RO'));
    S::keepMarkets($w, ['SK', 'CZ'])
        ->assertUnprocessable()->assertJsonPath('errors.0.code', 'wave.market_has_personas')
        ->assertJsonPath('errors.0.meta.markets.0.concept_count', 2);
    expect(Wave::query()->findOrFail($w['wave']->id)->markets->pluck('code')->all())->toBe(['SK', 'RO', 'CZ']);
});

it('the stepper counts: totals, per market, complete when every concept is approved, under the recommended three', function (): void {
    $w = S::world();
    $sk = C::persona($w);
    $ro = C::persona($w, 'RO');
    $a = C::make($w, $sk);
    $b = C::make($w, $sk);
    $c = C::make($w, $sk);
    $d = C::make($w, $ro);
    C::decide($w, $a, 'approved')->assertOk();
    C::decide($w, $b, 'approved')->assertOk();
    C::decide($w, $c, 'changes_requested')->assertOk();
    C::decide($w, $d, 'approved')->assertOk();

    $s = S::call($w['staff'], 'GET', S::u($w))->assertOk()->json('data.attributes.concepts');
    expect($s)->toMatchArray(['total' => 4, 'approved' => 3, 'awaiting' => 0, 'changes_requested' => 1, 'markets_complete' => 1]);
    $by = array_column($s['by_market'], null, 'market');
    $by = array_combine(array_map(static fn (string $u): string => array_search($u, $w['mk'], true), array_keys($by)), $by);
    expect($by['SK'])->toMatchArray(['total' => 3, 'approved' => 2, 'changes_requested' => 1, 'complete' => false, 'under_minimum' => false])
        ->and($by['RO'])->toMatchArray(['total' => 1, 'complete' => true, 'under_minimum' => true])
        ->and($by['CZ'])->toMatchArray(['total' => 0, 'complete' => false, 'under_minimum' => true])
        ->and(ConceptService::summary($w['wave'])['total'])->toBe(4);
    expect(S::call($w['staff'], 'GET', S::u($w))->json('data.attributes.limits'))->toHaveKeys(['concept_max_per_market', 'links_per_concept', 'images_per_concept', 'image_total_bytes']);
});

it('free text: limits per field, control characters removed, an empty optional field is null, a market cannot change', function (): void {
    $w = S::world();
    $p = C::persona($w);
    C::create($w, $p, ['title' => str_repeat('a', 121)])->assertUnprocessable();
    C::create($w, $p, ['title' => '   '])->assertUnprocessable();
    C::create($w, $p, ['funnel_note' => str_repeat('a', 41)])->assertUnprocessable();
    C::create($w, $p, ['funnel_stage' => 'middle'])->assertUnprocessable();
    C::create($w, $p, ['key_points' => array_fill(0, 9, 'x')])->assertUnprocessable();
    C::create($w, $p, ['hook' => str_repeat('a', 301)])->assertUnprocessable();
    C::create($w, $p, ['hypothesis' => str_repeat('a', 401)])->assertUnprocessable();

    $row = C::create($w, $p, ['title' => "Title\x00 with\x07 controls", 'hook' => '   '])->assertCreated()->json('data.attributes');
    expect($row['title'])->toBe('Title with controls')->and($row['hook'])->toBeNull();
    $id = (string) WaveConcept::query()->latest('id')->value('ulid');
    S::call($w['staff'], 'PATCH', S::u($w, "concepts/{$id}"), ['market' => $w['mk']['RO']])->assertUnprocessable();
    expect(strlen((string) json_encode(S::call($w['staff'], 'PATCH', S::u($w, "concepts/{$id}"), ['title' => '<script>alert(1)</script>'])->json('data.attributes.title'))))->toBeGreaterThan(10);
});

it('an archived brand is read-only: every concept write is 422 wave.brand_archived, reads still work', function (): void {
    $w = S::world();
    $p = C::persona($w);
    $id = C::make($w, $p);
    $w['brand']->forceFill(['status' => BrandStatus::Archived])->save();

    S::call($w['admin'], 'GET', S::u($w, "concepts?market={$w['mk']['SK']}"))->assertOk();
    foreach ([
        ['POST', 'concepts', ['market' => $w['mk']['SK'], 'persona' => $p->ulid, 'title' => 'x']],
        ['PATCH', "concepts/{$id}", ['title' => 'x']],
        ['POST', "concepts/{$id}/duplicate", []],
        ['DELETE', "concepts/{$id}", []],
        ['POST', "concepts/{$id}/decision", ['status' => 'approved', 'client_name' => 'A']],
        ['POST', "concepts/{$id}/links", ['url' => 'https://example.com', 'title' => 'x']],
        ['POST', "concepts/{$id}/images/uploads/init", ['mime_type' => 'image/png', 'declared_bytes' => 10, 'original_filename' => 'a.png']],
    ] as [$method, $suffix, $body]) {
        S::call($w['admin'], $method, S::u($w, $suffix), $body)->assertUnprocessable()->assertJsonPath('errors.0.code', 'wave.brand_archived');
    }
    expect(WaveConcept::query()->count())->toBe(1);
});
