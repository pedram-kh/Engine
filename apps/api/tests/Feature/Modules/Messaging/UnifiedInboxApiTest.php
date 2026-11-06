<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

require_once dirname(__DIR__, 3).'/Support/MessageInboxFixtures.php';

/**
 * AH-119 A2 — the unified inbox: the union, its order, its shape, its unread rules, its filters and
 * its pagination. Facets, system lines and tenancy have their own files.
 */
it('answers 404 not_found while messages_v2_inbox_enabled is OFF', function (): void {
    ['agency' => $agency, 'admin' => $admin] = inboxWorld(flag: false);

    $this->actingAs($admin)->getJson(inboxUrl($agency))->assertNotFound()->assertJsonPath('errors.0.code', 'not_found');
});

it('lists Direct and assignment threads in ONE list, newest activity first, one row per thread', function (): void {
    ['agency' => $agency, 'admin' => $admin] = inboxWorld();
    $creator = inboxCreator('Ada');

    $old = inboxDirect($agency, $creator, at: now()->subHours(3));
    $mid = inboxAssignment($agency, creator: $creator, at: now()->subHours(2));
    $new = inboxAssignment($agency, creator: $creator, at: now()->subHour());

    $response = $this->actingAs($admin)->getJson(inboxUrl($agency))->assertOk();

    expect(inboxList($response)->pluck('id')->all())
        ->toBe([$new['thread']->ulid, $mid['thread']->ulid, $old['thread']->ulid]);
    expect(inboxList($response)->pluck('type')->all())
        ->toBe(['assignment_thread', 'assignment_thread', 'relationship_thread']);
});

it('tags an assignment row with brand and campaign and a Direct row with neither', function (): void {
    ['agency' => $agency, 'admin' => $admin] = inboxWorld();
    $direct = inboxDirect($agency, inboxCreator('Dee'), at: now()->subHour());
    $asg = inboxAssignment($agency, at: now());
    $asg['brand']->forceFill(['color' => 'teal'])->save();

    $rows = inboxList($this->actingAs($admin)->getJson(inboxUrl($agency))->assertOk(), 'data')->keyBy('id');

    $a = $rows[$asg['thread']->ulid]['attributes'];
    expect($a['brand'])->toBe(['id' => $asg['brand']->ulid, 'name' => $asg['brand']->name, 'color' => 'teal'])
        ->and($a['campaign'])->toBe(['id' => $asg['campaign']->ulid, 'name' => $asg['campaign']->name])
        ->and($a['assignment_id'])->toBe($asg['assignment']->ulid)
        ->and($a['creator']['id'])->toBe($asg['creator']->ulid);

    $d = $rows[$direct['thread']->ulid]['attributes'];
    expect($d['brand'])->toBeNull()->and($d['campaign'])->toBeNull()->and($d['assignment_id'])->toBeNull()
        ->and($d['creator']['display_name'])->toBe('Dee');
});

it('counts unread MESSAGES per row for the viewer, and a read receipt clears only that viewer', function (): void {
    ['agency' => $agency, 'admin' => $admin, 'manager' => $manager] = inboxWorld();
    $direct = inboxDirect($agency, inboxCreator(), humans: 3);
    $asg = inboxAssignment($agency, humans: 2);

    inboxMarkRead($admin, $direct['messages'][0]);
    inboxMarkRead($admin, $asg['messages'][0]);

    $forAdmin = inboxList($this->actingAs($admin)->getJson(inboxUrl($agency)), 'data')->keyBy('id');
    expect($forAdmin[$direct['thread']->ulid]['attributes']['unread_count'])->toBe(2)
        ->and($forAdmin[$asg['thread']->ulid]['attributes']['unread_count'])->toBe(1);

    $forManager = inboxList($this->actingAs($manager)->getJson(inboxUrl($agency)), 'data')->keyBy('id');
    expect($forManager[$direct['thread']->ulid]['attributes']['unread_count'])->toBe(3)
        ->and($forManager[$asg['thread']->ulid]['attributes']['unread_count'])->toBe(2);
});

it('does not count the viewer\'s own messages as unread', function (): void {
    ['agency' => $agency, 'admin' => $admin] = inboxWorld();
    $direct = inboxDirect($agency, inboxCreator(), humans: 1);
    $direct['messages'][0]->forceFill(['sender_user_id' => $admin->id])->save();

    $row = $this->actingAs($admin)->getJson(inboxUrl($agency))->json('data.0.attributes');
    expect($row['unread_count'])->toBe(0);
});

it('shows the last human message as the preview, and a file-only message as an attachment preview', function (): void {
    ['agency' => $agency, 'admin' => $admin] = inboxWorld();
    $asg = inboxAssignment($agency, humans: 1, system: 2);
    $direct = inboxDirect($agency, inboxCreator());
    $direct['messages'][0]->forceFill(['body' => null, 'attachments' => [['kind' => 'file', 'name' => 'a.png']]])->save();

    $rows = inboxList($this->actingAs($admin)->getJson(inboxUrl($agency)), 'data')->keyBy('id');

    expect($rows[$asg['thread']->ulid]['attributes']['preview'])->toBe('assignment 0')
        ->and($rows[$asg['thread']->ulid]['attributes']['preview_kind'])->toBe('text')
        ->and($rows[$direct['thread']->ulid]['attributes']['preview'])->toBeNull()
        ->and($rows[$direct['thread']->ulid]['attributes']['preview_kind'])->toBe('attachment');
});

it('hides Direct rows under a brand or a campaign filter, and narrows assignment rows to it', function (): void {
    ['agency' => $agency, 'admin' => $admin] = inboxWorld();
    $direct = inboxDirect($agency, inboxCreator(), at: now());
    $a = inboxAssignment($agency, at: now()->subMinute());
    $b = inboxAssignment($agency, at: now()->subMinutes(2));

    $byBrand = inboxList($this->actingAs($admin)->getJson(inboxUrl($agency, 'brand[]='.$a['brand']->ulid)), 'data');
    expect($byBrand->pluck('id')->all())->toBe([$a['thread']->ulid]);

    $byCampaign = inboxList($this->actingAs($admin)->getJson(inboxUrl($agency, 'campaign[]='.$b['campaign']->ulid)), 'data');
    expect($byCampaign->pluck('id')->all())->toBe([$b['thread']->ulid]);

    $both = inboxList($this->actingAs($admin)->getJson(inboxUrl($agency, 'brand[]='.$a['brand']->ulid.'&brand[]='.$b['brand']->ulid)), 'data');
    expect($both->pluck('id')->all())->toBe([$a['thread']->ulid, $b['thread']->ulid])
        ->and($both->pluck('id')->contains($direct['thread']->ulid))->toBeFalse();
});

it('filters to unread threads only', function (): void {
    ['agency' => $agency, 'admin' => $admin] = inboxWorld();
    $read = inboxDirect($agency, inboxCreator());
    inboxMarkRead($admin, $read['messages'][0]);
    $unread = inboxAssignment($agency);

    $ids = inboxList($this->actingAs($admin)->getJson(inboxUrl($agency, 'unread=1')), 'data')->pluck('id')->all();
    expect($ids)->toBe([$unread['thread']->ulid]);
});

it('pages across both sources with EQUAL timestamps, without a gap or a repeat', function (): void {
    ['agency' => $agency, 'admin' => $admin] = inboxWorld();
    $at = now()->subDay()->startOfSecond();
    $expected = [];
    for ($i = 0; $i < 4; $i++) {
        $expected[] = inboxDirect($agency, inboxCreator("d{$i}"), at: $at)['thread']->ulid;
        $expected[] = inboxAssignment($agency, at: $at)['thread']->ulid;
    }

    $seen = [];
    $cursor = null;
    $pages = 0;
    do {
        $query = 'per_page=3'.($cursor === null ? '' : '&cursor='.urlencode($cursor));
        $res = $this->actingAs($admin)->getJson(inboxUrl($agency, $query))->assertOk();
        array_push($seen, ...inboxList($res, 'data')->pluck('id')->all());
        $cursor = $res->json('meta.next_cursor');
        $pages++;
    } while ($cursor !== null && $pages < 10);

    expect($pages)->toBe(3)
        ->and(count($seen))->toBe(8)
        ->and(count(array_unique($seen)))->toBe(8)
        ->and(array_diff($expected, $seen))->toBe([]);
});

it('caps per_page at 100 and defaults to 30', function (): void {
    ['agency' => $agency, 'admin' => $admin] = inboxWorld();
    for ($i = 0; $i < 31; $i++) {
        inboxDirect($agency, inboxCreator("c{$i}"), at: now()->subMinutes($i));
    }

    expect($this->actingAs($admin)->getJson(inboxUrl($agency))->json('data'))->toHaveCount(30);
    expect($this->actingAs($admin)->getJson(inboxUrl($agency, 'per_page=1000'))->json('data'))->toHaveCount(31);
});

it('lets a staff member read the inbox (any active member)', function (): void {
    ['agency' => $agency, 'staff' => $staff] = inboxWorld();
    inboxDirect($agency, inboxCreator());

    $this->actingAs($staff)->getJson(inboxUrl($agency))->assertOk()->assertJsonCount(1, 'data');
});

it('runs a flat number of queries however many threads there are', function (): void {
    ['agency' => $agency, 'admin' => $admin] = inboxWorld();
    $count = function () use ($agency, $admin): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)->getJson(inboxUrl($agency))->assertOk();

        return count(DB::getQueryLog());
    };

    inboxDirect($agency, inboxCreator());
    inboxAssignment($agency);
    $small = $count();

    for ($i = 0; $i < 12; $i++) {
        inboxDirect($agency, inboxCreator("x{$i}"));
        inboxAssignment($agency);
    }

    expect($count())->toBe($small);
});
