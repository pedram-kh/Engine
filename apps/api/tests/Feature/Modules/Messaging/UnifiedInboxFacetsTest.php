<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

require_once dirname(__DIR__, 3).'/Support/MessageInboxFixtures.php';

/**
 * AH-119 A2 — facets and the pill. Rulings: Q1 (popover and pill count THREADS with unread, the row
 * badge counts MESSAGES), P6 (the pill is the UNFILTERED total).
 */
it('counts threads with unread in the popovers and the pill, while the row badge counts messages', function (): void {
    ['agency' => $agency, 'admin' => $admin] = inboxWorld();
    $a = inboxAssignment($agency, humans: 3);                       // one thread, 3 unread messages
    inboxAssignment($agency, brand: $a['brand'], humans: 2);        // a second thread of the same brand
    $read = inboxAssignment($agency, brand: $a['brand']);           // a third, fully read: in the brand, not in the count
    inboxMarkRead($admin, $read['messages'][0]);
    inboxDirect($agency, inboxCreator(), humans: 4);                // a Direct thread

    $res = $this->actingAs($admin)->getJson(inboxUrl($agency))->assertOk();

    $brand = inboxList($res, 'meta.facets.brands')->firstWhere('id', $a['brand']->ulid);
    expect($brand['unread_threads'])->toBe(2)                          // threads, not 5 messages
        ->and($res->json('meta.unread_threads_total'))->toBe(3)        // the pill: 2 + the Direct thread
        ->and(inboxList($res, 'data')->sum('attributes.unread_count'))->toBe(9);
});

it('keeps the pill UNFILTERED whatever the filters, and a thread with no unread counts 0 in its facet', function (): void {
    ['agency' => $agency, 'admin' => $admin] = inboxWorld();
    $x = inboxAssignment($agency);
    $y = inboxAssignment($agency);
    inboxMarkRead($admin, $y['messages'][0]);

    $res = $this->actingAs($admin)->getJson(inboxUrl($agency, 'brand[]='.$y['brand']->ulid.'&unread=1&owner=me'))->assertOk();

    expect($res->json('meta.unread_threads_total'))->toBe(1)
        ->and(inboxList($res, 'meta.facets.brands')->firstWhere('id', $y['brand']->ulid)['unread_threads'] ?? null)->toBeNull();
});

it('ignores its OWN selection in a facet but follows the other facet', function (): void {
    ['agency' => $agency, 'admin' => $admin] = inboxWorld();
    $a = inboxAssignment($agency);
    $b = inboxAssignment($agency);
    $a2 = inboxAssignment($agency, brand: $a['brand']);

    // Select brand A: the BRAND facet still lists both brands (own selection ignored) ...
    $res = $this->actingAs($admin)->getJson(inboxUrl($agency, 'brand[]='.$a['brand']->ulid))->assertOk();
    expect(inboxList($res, 'meta.facets.brands')->pluck('id')->sort()->values()->all())
        ->toBe(collect([$a['brand']->ulid, $b['brand']->ulid])->sort()->values()->all());

    // ... while the CAMPAIGN facet follows the brand selection (only brand A's two campaigns).
    expect(inboxList($res, 'meta.facets.campaigns')->pluck('id')->sort()->values()->all())
        ->toBe(collect([$a['campaign']->ulid, $a2['campaign']->ulid])->sort()->values()->all());

    // Select a campaign: the CAMPAIGN facet keeps listing its siblings.
    $res2 = $this->actingAs($admin)->getJson(inboxUrl($agency, 'campaign[]='.$a['campaign']->ulid))->assertOk();
    expect(inboxList($res2, 'meta.facets.campaigns')->pluck('id')->all())->toContain($a['campaign']->ulid, $a2['campaign']->ulid, $b['campaign']->ulid);
});

it('does not apply the unread filter to facet counts, and follows Mine', function (): void {
    ['agency' => $agency, 'admin' => $admin, 'manager' => $manager] = inboxWorld();
    $mine = inboxAssignment($agency, owner: $admin);
    $theirs = inboxAssignment($agency, owner: $manager);
    inboxMarkRead($admin, $mine['messages'][0]);

    $res = $this->actingAs($admin)->getJson(inboxUrl($agency, 'unread=1'))->assertOk();
    $ids = inboxList($res, 'meta.facets.brands')->pluck('id')->all();
    expect($ids)->toContain($mine['brand']->ulid, $theirs['brand']->ulid);

    $me = $this->actingAs($admin)->getJson(inboxUrl($agency, 'owner=me'))->assertOk();
    expect(inboxList($me, 'meta.facets.brands')->pluck('id')->all())->toBe([$mine['brand']->ulid]);
});

it('lists a brand colour in the facet, null when none was chosen', function (): void {
    ['agency' => $agency, 'admin' => $admin] = inboxWorld();
    $a = inboxAssignment($agency);
    $a['brand']->forceFill(['color' => 'pink'])->save();
    $b = inboxAssignment($agency);

    $facet = inboxList($this->actingAs($admin)->getJson(inboxUrl($agency)), 'meta.facets.brands')->keyBy('id');
    expect($facet[$a['brand']->ulid]['color'])->toBe('pink')->and($facet[$b['brand']->ulid]['color'])->toBeNull();
});
