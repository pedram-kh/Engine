<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

require_once dirname(__DIR__, 3).'/Support/MessageRevisionFixtures.php';

/*
 * AH-119 B3 — search is tenancy-bound on EACH table: another agency's identical text never surfaces.
 */

it('finds only the own agency\'s messages, in BOTH tables, when another agency holds the same text', function (): void {
    $mine = revWorld();
    $theirs = revWorld();
    $myC = revCampaignMessage($mine['thread'], $mine['admin'], false, 'tenantword campaign');
    $myD = revDirectMessage($mine['direct'], $mine['admin'], false, 'tenantword direct');
    $theirC = revCampaignMessage($theirs['thread'], $theirs['admin'], false, 'tenantword campaign');
    $theirD = revDirectMessage($theirs['direct'], $theirs['admin'], false, 'tenantword direct');

    $found = revSearch($mine, 'tenantword')->assertOk()->json('data.*.attributes.message_ulid');
    $theirFound = revSearch($theirs, 'tenantword')->assertOk()->json('data.*.attributes.message_ulid');

    expect($found)->toEqualCanonicalizing([$myC->ulid, $myD->ulid])
        ->and($theirFound)->toEqualCanonicalizing([$theirC->ulid, $theirD->ulid]);
});

it('refuses searching another agency\'s path with 404 and leaks no row', function (): void {
    $mine = revWorld();
    $theirs = revWorld();
    revDirectMessage($theirs['direct'], $theirs['admin'], false, 'secretword');

    $this->actingAs($mine['admin'])->getJson("/api/v1/agencies/{$theirs['agency']->ulid}/message-search?q=secretword")->assertNotFound();
    expect(revSearch($mine, 'secretword')->json('data'))->toBe([]);
});

it('does not rank or cursor a foreign hit into the page: a cursor cannot widen the scope', function (): void {
    $mine = revWorld();
    $theirs = revWorld();
    foreach (range(1, 40) as $i) {
        revDirectMessage($theirs['direct'], $theirs['admin'], false, "foreign marker {$i}");
    }
    revDirectMessage($mine['direct'], $mine['admin'], false, 'marker mine only');

    $res = revSearch($mine, 'marker', base64_encode('{"o":0}'))->assertOk();

    expect($res->json('data'))->toHaveCount(1)->and($res->json('meta.next_cursor'))->toBeNull();
});

it('applies the tenancy filter in SQL on EACH table, not only when rows are turned into inbox rows', function (string $table): void {
    // Foreign hits would take the page slots (they are newer) and push the agency's only hit off page one.
    $mine = revWorld();
    $theirs = revWorld();
    $make = $table === 'messages'
        ? fn (array $w, string $body) => revCampaignMessage($w['thread'], $w['admin'], false, $body)
        : fn (array $w, string $body) => revDirectMessage($w['direct'], $w['admin'], false, $body);
    $own = $make($mine, 'slotword mine');
    foreach (range(1, 35) as $i) {
        $make($theirs, "slotword foreign {$i}");
    }

    $res = revSearch($mine, 'slotword')->assertOk();

    expect($res->json('data.*.attributes.message_ulid'))->toBe([$own->ulid])->and($res->json('meta.next_cursor'))->toBeNull();
})->with(['messages', 'relationship_messages']);
