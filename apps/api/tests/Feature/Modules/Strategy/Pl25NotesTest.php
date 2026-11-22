<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\AgencyMembership;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Models\WaveStrategyNote;
use App\Modules\Strategy\Notes\NoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Pl25Fixtures as P;
use Tests\Support\StrategyFixtures as S;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** PL-2.5 B15: internal notes, per step and per market: ownership, isolation, limits, plain text, audit. */
/** @return TestResponse<Response> */
function p25Note(array $w, string $who, array $body): TestResponse
{
    return S::call($w[$who], 'POST', S::u($w, 'notes'), $body);
}

function p25Thread(array $w, string $step, ?string $market = null, string $who = 'staff'): array
{
    $q = '?step='.$step.($market === null ? '' : '&market='.$market);

    return S::call($w[$who], 'GET', S::u($w, 'notes').$q)->assertOk()->json('data');
}

it('B15: a note is written, listed oldest first with author, own flags and no edited marker', function (): void {
    $w = S::world();
    $a = p25Note($w, 'staff', ['step' => 'usps', 'body' => 'First'])->assertCreated();
    p25Note($w, 'manager', ['step' => 'usps', 'body' => 'Second'])->assertCreated();

    $thread = p25Thread($w, 'usps');
    expect(array_column(array_column($thread, 'attributes'), 'body'))->toBe(['First', 'Second'])
        ->and($thread[0]['attributes'])->toMatchArray(['edited' => false, 'can_edit' => true, 'can_delete' => true, 'author_is_former' => false])
        ->and($thread[1]['attributes'])->toMatchArray(['can_edit' => false, 'can_delete' => false])
        ->and($a->json('data.attributes.author.name'))->toBe($w['staff']->name);
});

it('B15 / K4: a thread is per step and per market: market A never shows in market B or in USPs', function (): void {
    $w = S::world();
    p25Note($w, 'staff', ['step' => 'personas', 'market' => $w['mk']['SK'], 'body' => 'SK personas'])->assertCreated();
    p25Note($w, 'staff', ['step' => 'matrix', 'market' => $w['mk']['SK'], 'body' => 'SK matrix'])->assertCreated();
    p25Note($w, 'staff', ['step' => 'usps', 'body' => 'USP note'])->assertCreated();

    expect(p25Thread($w, 'personas', $w['mk']['SK']))->toHaveCount(1)
        ->and(p25Thread($w, 'personas', $w['mk']['RO']))->toBe([])
        ->and(p25Thread($w, 'matrix', $w['mk']['SK']))->toHaveCount(1)
        ->and(p25Thread($w, 'concepts', $w['mk']['SK']))->toBe([])
        ->and(p25Thread($w, 'usps'))->toHaveCount(1);
});

it('B15 / K4: edit is author-only; delete is the author or admin/manager; staff cannot touch another\'s', function (): void {
    $w = S::world();
    $id = p25Note($w, 'staff', ['step' => 'usps', 'body' => 'Mine'])->json('data.id');

    S::call($w['manager'], 'PATCH', S::u($w, "notes/{$id}"), ['body' => 'Hijack'])->assertForbidden();
    S::call($w['admin'], 'PATCH', S::u($w, "notes/{$id}"), ['body' => 'Hijack'])->assertForbidden();
    S::call($w['staff'], 'PATCH', S::u($w, "notes/{$id}"), ['body' => 'Edited'])->assertOk()->assertJsonPath('data.attributes.edited', true);

    $other = p25Note($w, 'manager', ['step' => 'usps', 'body' => 'Theirs'])->json('data.id');
    S::call($w['staff'], 'DELETE', S::u($w, "notes/{$other}"))->assertForbidden();
    S::call($w['admin'], 'DELETE', S::u($w, "notes/{$id}"))->assertNoContent();
    S::call($w['manager'], 'DELETE', S::u($w, "notes/{$other}"))->assertNoContent();
    expect(WaveStrategyNote::query()->count())->toBe(0);
});

it('B15: empty and over-long bodies are refused, 2000 is accepted, the 101st note of a thread is refused', function (): void {
    $w = S::world();

    p25Note($w, 'staff', ['step' => 'usps', 'body' => ''])->assertStatus(422);
    p25Note($w, 'staff', ['step' => 'usps', 'body' => "  \n "])->assertStatus(422);
    p25Note($w, 'staff', ['step' => 'usps', 'body' => str_repeat('a', NoteService::BODY_MAX + 1)])->assertStatus(422)->assertJsonPath('errors.0.code', 'note.body_invalid');
    p25Note($w, 'staff', ['step' => 'usps', 'body' => str_repeat('a', NoteService::BODY_MAX)])->assertCreated();

    for ($i = 1; $i < NoteService::THREAD_MAX; $i++) {
        WaveStrategyNote::factory()->create(['agency_id' => $w['agency']->id, 'wave_id' => $w['wave']->id, 'step' => 'usps', 'market_id' => null, 'author_user_id' => $w['staff']->id]);
    }
    p25Note($w, 'staff', ['step' => 'usps', 'body' => 'one too many'])->assertStatus(422)->assertJsonPath('errors.0.code', 'note.thread_full');
});

it('B15: the step and market pairing is checked (usps takes no market, others require one of this wave; concepts allowed, other steps 422)', function (): void {
    $w = S::world();

    p25Note($w, 'staff', ['step' => 'usps', 'market' => $w['mk']['SK'], 'body' => 'x'])->assertStatus(422)->assertJsonPath('errors.0.code', 'strategy.market_not_allowed');
    p25Note($w, 'staff', ['step' => 'personas', 'body' => 'x'])->assertStatus(422)->assertJsonPath('errors.0.code', 'strategy.market_required');
    p25Note($w, 'staff', ['step' => 'personas', 'market' => S::NO_MARKET, 'body' => 'x'])->assertStatus(422);
    p25Note($w, 'staff', ['step' => 'concepts', 'market' => $w['mk']['CZ'], 'body' => 'x'])->assertCreated();
    p25Note($w, 'staff', ['step' => 'creators', 'body' => 'x'])->assertStatus(404);
});

it('B15: a hostile string is stored and returned as plain text, unchanged', function (): void {
    $w = S::world();
    $hostile = '<img src=x onerror=alert(1)> {{7*7}} <script>alert(1)</script> ${jndi:x} \' OR 1=1 --';

    p25Note($w, 'staff', ['step' => 'usps', 'body' => $hostile])->assertCreated();

    expect(p25Thread($w, 'usps')[0]['attributes']['body'])->toBe($hostile);
});

it('B15: a note by someone who has left shows as a former member with no name; the audit holds the length, never the text', function (): void {
    $w = S::world();
    $id = p25Note($w, 'staff', ['step' => 'usps', 'body' => 'Secret-text-123'])->json('data.id');

    $log = AuditLog::query()->where('action', 'wave_strategy_note.created')->get();
    expect($log)->toHaveCount(1)->and(json_encode(P::meta($log[0])))->not->toContain('Secret-text-123')
        ->and(P::meta($log[0]))->toMatchArray(['length' => 15, 'step' => 'usps']);

    AgencyMembership::query()->where('user_id', $w['staff']->id)->where('agency_id', $w['agency']->id)->delete();
    $attrs = collect(p25Thread($w, 'usps', null, 'admin'))->firstWhere('id', $id)['attributes'];
    expect($attrs['author'])->toBeNull()->and($attrs['author_is_former'])->toBeTrue()->and(json_encode($attrs))->not->toContain($w['staff']->name);

    User::query()->whereKey($w['staff']->id)->delete();
    expect(collect(p25Thread($w, 'usps', null, 'admin'))->firstWhere('id', $id)['attributes']['author_is_former'])->toBeTrue();
});

it('B15: update and delete are audited with a length only; a note of another wave is a 404 through this wave', function (): void {
    $w = S::world();
    $id = p25Note($w, 'staff', ['step' => 'usps', 'body' => 'abc'])->json('data.id');
    S::call($w['staff'], 'PATCH', S::u($w, "notes/{$id}"), ['body' => 'abcdef'])->assertOk();

    expect(P::meta(AuditLog::query()->where('action', 'wave_strategy_note.updated')->first()))->toMatchArray(['length' => 6]);

    S::call($w['otherAdmin'], 'GET', S::u($w, 'notes').'?step=usps')->assertNotFound();
    S::call($w['admin'], 'PATCH', S::url($w['agency'], $w['brand'], $w['otherWave'], "notes/{$id}"), ['body' => 'x'])->assertStatus(404);

    S::call($w['staff'], 'DELETE', S::u($w, "notes/{$id}"))->assertNoContent();
    expect(AuditLog::query()->where('action', 'wave_strategy_note.deleted')->count())->toBe(1);
});

it('B15: the summary carries note counts per step and market, never a body', function (): void {
    $w = S::world();
    p25Note($w, 'staff', ['step' => 'usps', 'body' => 'Counted-body-xyz'])->assertCreated();
    p25Note($w, 'staff', ['step' => 'personas', 'market' => $w['mk']['SK'], 'body' => 'b'])->assertCreated();
    p25Note($w, 'staff', ['step' => 'personas', 'market' => $w['mk']['SK'], 'body' => 'c'])->assertCreated();

    $res = S::call($w['admin'], 'GET', S::u($w))->assertOk();
    expect($res->json('data.attributes.note_counts'))->toMatchArray(['usps' => 1, 'personas' => [$w['mk']['SK'] => 2]])
        ->and($res->getContent())->not->toContain('Counted-body-xyz');
});
