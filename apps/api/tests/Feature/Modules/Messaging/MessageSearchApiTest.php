<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Services\MessageSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

require_once dirname(__DIR__, 3).'/Support/MessageRevisionFixtures.php';

/*
 * AH-119 B3 — the agency message search. These run on the SQLite fallback (substring LIKE); the
 * Postgres tsvector behaviour is MessageSearchPgTest. Words are placed at word starts so both agree.
 */

it('finds a campaign message and a direct message in one list, each with its inbox row', function (): void {
    $w = revWorld();
    $c = revCampaignMessage($w['thread'], $w['creatorUser'], true, 'Contract draft attached');
    $d = revDirectMessage($w['direct'], $w['admin'], false, 'About the contract timeline');

    $res = revSearch($w, 'contract')->assertOk();

    /** @var list<array<string, mixed>> $data */
    $data = $res->json('data');
    $byType = collect($data)->keyBy('type');
    expect($byType->keys()->sort()->values()->all())->toBe(['assignment_thread', 'relationship_thread'])
        ->and(data_get($byType['assignment_thread'], 'attributes.message_ulid'))->toBe($c->ulid)
        ->and(data_get($byType['relationship_thread'], 'attributes.message_ulid'))->toBe($d->ulid)
        ->and(data_get($byType['assignment_thread'], 'attributes'))->toHaveKeys(['snippet', 'sent_at', 'sender_role', 'sender_is_erased', 'creator', 'unread_count'])
        ->and($res->json('meta.per_page'))->toBe(30)->and($res->json('meta.next_cursor'))->toBeNull();
});

it('is case-insensitive, matches every term, and ANDs them', function (): void {
    $w = revWorld();
    revDirectMessage($w['direct'], $w['admin'], false, 'Invoice for the Spring shoot');
    revDirectMessage($w['direct'], $w['admin'], false, 'Invoice only');

    expect(revSearch($w, 'SPRING invoice')->json('data'))->toHaveCount(1)
        ->and(revSearch($w, 'invoice')->json('data'))->toHaveCount(2);
});

it('answers 422 below two characters and returns nothing for terms that are all too short', function (): void {
    $w = revWorld();
    revDirectMessage($w['direct'], $w['admin'], false, 'a b c');

    revSearch($w, 'a')->assertUnprocessable();
    revSearch($w, '')->assertUnprocessable();
    revSearch($w, ' ')->assertUnprocessable();
    expect(revSearch($w, 'a b')->assertOk()->json('data'))->toBe([]);
});

it('caps the terms and their length, and treats punctuation as separators', function (): void {
    $service = app(MessageSearch::class);

    expect($service->terms('one two three four five six seven eight nine ten'))->toHaveCount(MessageSearch::MAX_TERMS)
        ->and($service->terms(str_repeat('x', 100))[0])->toHaveLength(MessageSearch::MAX_TERM_LENGTH)
        ->and($service->terms("it's (a) well-known: o'neil; a"))->toBe(['it', 'well', 'known', 'neil'])
        ->and($service->terms('Dup dup DUP'))->toBe(['dup'])
        ->and($service->terms("'; drop table messages; --"))->toBe(['drop', 'table', 'messages']);
});

it('treats LIKE wildcards literally: % and _ match nothing special', function (): void {
    $w = revWorld();
    revDirectMessage($w['direct'], $w['admin'], false, 'a 100% sure thing');
    revDirectMessage($w['direct'], $w['admin'], false, 'plain words here');

    // Separators strip the symbols, so `100%` is the term `100`; a bare wildcard query is empty.
    expect(revSearch($w, '100%')->json('data'))->toHaveCount(1)
        ->and(revSearch($w, '%%')->assertOk()->json('data'))->toBe([])
        ->and(revSearch($w, '__')->assertOk()->json('data'))->toBe([])
        // `_` is a separator too (so `p_plain` is the term `plain`): no wildcard ever reaches the LIKE.
        ->and(revSearch($w, 'p_plain')->json('data'))->toHaveCount(1)
        ->and(revSearch($w, 'zz_in')->json('data'))->toHaveCount(0);
});

it('rejects an over-long query and a malformed cursor safely', function (): void {
    $w = revWorld();
    revDirectMessage($w['direct'], $w['admin'], false, 'findable text');

    revSearch($w, str_repeat('a', 201))->assertUnprocessable();
    expect(revSearch($w, 'findable', '!!not-base64!!')->assertOk()->json('data'))->toHaveCount(1)
        ->and(revSearch($w, 'findable', base64_encode('{"o":-5}'))->assertOk()->json('data'))->toHaveCount(1);
});

it('caps the snippet at 160 characters of plain text, centred on the match, with no markup', function (): void {
    $w = revWorld();
    revDirectMessage($w['direct'], $w['admin'], false, str_repeat('padding ', 40).'<b>needle</b> '.str_repeat('tail ', 80));

    $snippet = revSearch($w, 'needle')->json('data.0.attributes.snippet');

    expect(mb_strlen($snippet))->toBeLessThanOrEqual(MessageSearch::SNIPPET_LENGTH + 2)
        ->and($snippet)->toContain('needle')->and($snippet)->toStartWith('…')->and($snippet)->toEndWith('…');
});

it('returns a short body whole, without ellipses', function (): void {
    $w = revWorld();
    revDirectMessage($w['direct'], $w['admin'], false, 'short needle body');

    expect(revSearch($w, 'needle')->json('data.0.attributes.snippet'))->toBe('short needle body');
});

it('pages 30 at a time, newest first, with an opaque cursor that ends', function (): void {
    $w = revWorld();
    foreach (range(1, 35) as $i) {
        revDirectMessage($w['direct'], $w['admin'], false, "paging marker {$i}");
    }

    $first = revSearch($w, 'marker')->assertOk();
    expect($first->json('data'))->toHaveCount(30)->and($first->json('meta.next_cursor'))->toBeString();

    $second = revSearch($w, 'marker', $first->json('meta.next_cursor'))->assertOk();
    $all = array_merge($first->json('data.*.attributes.message_ulid'), $second->json('data.*.attributes.message_ulid'));

    expect($second->json('data'))->toHaveCount(5)->and($second->json('meta.next_cursor'))->toBeNull()
        ->and(array_unique($all))->toHaveCount(35);
});

it('is open to every active member of the agency, creators and strangers refused', function (): void {
    $w = revWorld();
    revDirectMessage($w['direct'], $w['admin'], false, 'visible to staff');

    expect(revSearch($w, 'visible', null, $w['staff'])->assertOk()->json('data'))->toHaveCount(1);
    revSearch($w, 'visible', null, $w['creatorUser'])->assertNotFound();
    revSearch($w, 'visible', null, User::factory()->agencyAdmin()->createOne())->assertNotFound();
});

it('never matches a system line', function (): void {
    $w = revWorld();
    Message::factory()->system('assignment.offer_edited')->createOne(['thread_id' => $w['thread']->id, 'body' => 'offer updated systemword']);

    expect(revSearch($w, 'systemword')->assertOk()->json('data'))->toBe([]);
});

it('does not run a search for an anonymous caller', function (): void {
    $w = revWorld();

    $this->getJson("/api/v1/agencies/{$w['agency']->ulid}/message-search?q=hello")->assertUnauthorized();
});

it('marks only the viewer\'s own messages with is_own, per viewer, on both thread kinds', function (): void {
    $w = revWorld();
    $mine = revCampaignMessage($w['thread'], $w['admin'], false, 'ownword by the admin');
    $colleague = revDirectMessage($w['direct'], $w['staff'], false, 'ownword by a colleague');
    $creator = revCampaignMessage($w['thread'], $w['creatorUser'], true, 'ownword by the creator');

    /** @var list<array<string, mixed>> $adminData */
    $adminData = revSearch($w, 'ownword')->assertOk()->json('data');
    $asAdmin = collect($adminData)->keyBy('attributes.message_ulid');
    expect(data_get($asAdmin[$mine->ulid], 'attributes.is_own'))->toBeTrue()
        ->and(data_get($asAdmin[$colleague->ulid], 'attributes.is_own'))->toBeFalse()
        ->and(data_get($asAdmin[$creator->ulid], 'attributes.is_own'))->toBeFalse();

    // The same hits, read by the colleague: the flag follows the viewer.
    /** @var list<array<string, mixed>> $staffData */
    $staffData = revSearch($w, 'ownword', null, $w['staff'])->assertOk()->json('data');
    $asStaff = collect($staffData)->keyBy('attributes.message_ulid');
    expect(data_get($asStaff[$mine->ulid], 'attributes.is_own'))->toBeFalse()
        ->and(data_get($asStaff[$colleague->ulid], 'attributes.is_own'))->toBeTrue();
});
