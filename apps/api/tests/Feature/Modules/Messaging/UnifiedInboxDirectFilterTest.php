<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

require_once dirname(__DIR__, 3).'/Support/MessageInboxFixtures.php';

/**
 * AH-119 walk finding: `direct=1` keeps only the Direct (relationship) threads, and combines with
 * Mine and Unread.
 */
it('lists only the Direct threads with direct=1', function (): void {
    ['agency' => $agency, 'admin' => $admin] = inboxWorld();
    inboxAssignment($agency);
    inboxDirect($agency, inboxCreator('Ana'));
    inboxDirect($agency, inboxCreator('Bo'));

    $all = inboxList($this->actingAs($admin)->getJson(inboxUrl($agency))->assertOk());
    $direct = inboxList($this->actingAs($admin)->getJson(inboxUrl($agency, 'direct=1'))->assertOk());

    expect($all)->toHaveCount(3)
        ->and($direct)->toHaveCount(2)
        ->and($direct->pluck('type')->unique()->all())->toBe(['relationship_thread']);
});

it('combines direct with Mine and Unread', function (): void {
    ['agency' => $agency, 'admin' => $admin, 'manager' => $manager] = inboxWorld();
    $mineUnread = inboxDirect($agency, inboxCreator('Mine unread'), owner: $admin);
    $mineRead = inboxDirect($agency, inboxCreator('Mine read'), owner: $admin);
    inboxMarkRead($admin, $mineRead['messages'][0]);
    inboxDirect($agency, inboxCreator('Theirs'), owner: $manager);
    inboxAssignment($agency, owner: $admin);

    $res = $this->actingAs($admin)->getJson(inboxUrl($agency, 'direct=1&owner=me&unread=1'))->assertOk();

    expect(inboxList($res))->toHaveCount(1)
        ->and(inboxList($res)->first()['id'])->toBe($mineUnread['thread']->ulid);
});

it('leaves the pill unfiltered under direct=1 and rejects a non-boolean', function (): void {
    ['agency' => $agency, 'admin' => $admin] = inboxWorld();
    inboxAssignment($agency);
    inboxDirect($agency, inboxCreator());

    $res = $this->actingAs($admin)->getJson(inboxUrl($agency, 'direct=1'))->assertOk();
    expect($res->json('meta.unread_threads_total'))->toBe(2);

    $this->actingAs($admin)->getJson(inboxUrl($agency, 'direct=maybe'))->assertStatus(422);
});
