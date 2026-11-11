<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Features\MessagesV2CreatorInboxEnabled;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\RelationshipMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

require_once dirname(__DIR__, 3).'/Support/MessageInboxFixtures.php';

/*
 * AH-115 L4 — the creator unified inbox and search: flag, scoping, order and cursor, unread, tag
 * data, search scope, and a query-count guard.
 */

const CREATOR_INBOX_URL = '/api/v1/creators/me/message-inbox';
const CREATOR_SEARCH_URL = '/api/v1/creators/me/message-search';

function creatorInboxFlag(bool $on = true): void
{
    $on ? Feature::activate(MessagesV2CreatorInboxEnabled::NAME) : Feature::deactivate(MessagesV2CreatorInboxEnabled::NAME);
}

/** A message FROM the agency admin into the creator's direct thread (so it is unread for the creator). */
function creatorInboxAgencyDirect(array $direct, User $admin, string $body = 'from agency'): RelationshipMessage
{
    return RelationshipMessage::factory()->createOne([
        'thread_id' => $direct['thread']->id,
        'sender_user_id' => $admin->id,
        'sender_role' => 'agency_user',
        'body' => $body,
    ]);
}

it('answers 404 not_found on both endpoints while messages_v2_creator_inbox_enabled is OFF', function (): void {
    creatorInboxFlag(false);
    $creator = inboxCreator('Ada');

    $this->actingAs($creator->user)->getJson(CREATOR_INBOX_URL)->assertNotFound()->assertJsonPath('errors.0.code', 'not_found');
    $this->actingAs($creator->user)->getJson(CREATOR_SEARCH_URL.'?q=hello')->assertNotFound()->assertJsonPath('errors.0.code', 'not_found');
});

it('does not touch the previous creator inbox while the flag is off', function (): void {
    creatorInboxFlag(false);
    ['agency' => $agency] = inboxWorld(flag: false);
    $creator = inboxCreator('Ada');
    inboxDirect($agency, $creator);

    $this->actingAs($creator->user)->getJson('/api/v1/creators/me/relationship-threads')->assertOk()->assertJsonCount(1, 'data');
});

it('lists direct and assignment threads in ONE list, newest first, with the tag data', function (): void {
    creatorInboxFlag();
    ['agency' => $agency] = inboxWorld(flag: false);
    $creator = inboxCreator('Ada');

    $old = inboxDirect($agency, $creator, at: now()->subHours(3));
    $asg = inboxAssignment($agency, creator: $creator, at: now()->subHour());

    $response = $this->actingAs($creator->user)->getJson(CREATOR_INBOX_URL)->assertOk();
    $rows = inboxList($response);

    expect($rows->pluck('id')->all())->toBe([$asg['thread']->ulid, $old['thread']->ulid])
        ->and($rows->pluck('type')->all())->toBe(['assignment_thread', 'relationship_thread']);

    $a = $rows[0]['attributes'];
    expect($a['brand'])->toBe(['id' => $asg['brand']->ulid, 'name' => $asg['brand']->name])
        ->and($a['campaign'])->toBe(['id' => $asg['campaign']->ulid, 'name' => $asg['campaign']->name])
        ->and($a['assignment_id'])->toBe($asg['assignment']->ulid)
        ->and($a['agency']['id'])->toBe($agency->ulid)
        ->and($a['agency']['name'])->toBe($agency->name);

    $d = $rows[1]['attributes'];
    expect($d['brand'])->toBeNull()->and($d['campaign'])->toBeNull()->and($d['assignment_id'])->toBeNull();
});

it('never lists another creator\'s thread, in either table', function (): void {
    creatorInboxFlag();
    ['agency' => $agency] = inboxWorld(flag: false);
    $mine = inboxCreator('Mine');
    $other = inboxCreator('Other');

    $own = inboxDirect($agency, $mine);
    inboxDirect($agency, $other);
    inboxAssignment($agency, creator: $other);

    $ids = inboxList($this->actingAs($mine->user)->getJson(CREATOR_INBOX_URL)->assertOk())->pluck('id')->all();

    expect($ids)->toBe([$own['thread']->ulid]);
});

it('lists the creator\'s threads across agencies', function (): void {
    creatorInboxFlag();
    ['agency' => $one] = inboxWorld(flag: false);
    ['agency' => $two] = inboxWorld(flag: false);
    $creator = inboxCreator('Ada');
    inboxDirect($one, $creator, at: now()->subHour());
    inboxAssignment($two, creator: $creator, at: now());

    $rows = inboxList($this->actingAs($creator->user)->getJson(CREATOR_INBOX_URL)->assertOk());

    expect($rows->pluck('attributes.agency.id')->all())->toBe([$two->ulid, $one->ulid]);
});

it('counts unread messages for the viewer only and ignores system lines', function (): void {
    creatorInboxFlag();
    ['agency' => $agency, 'admin' => $admin] = inboxWorld(flag: false);
    $creator = inboxCreator('Ada');

    $direct = inboxDirect($agency, $creator, humans: 1);
    $direct['messages'][0]->forceFill(['sender_user_id' => $admin->id, 'sender_role' => 'agency_user'])->save();
    creatorInboxAgencyDirect($direct, $admin, 'second');
    $asg = inboxAssignment($agency, creator: $creator, humans: 1, system: 2);
    Message::query()->whereKey($asg['messages'][0]->id)->update(['sender_user_id' => $admin->id, 'sender_role' => 'agency_user']);

    $rows = inboxList($this->actingAs($creator->user)->getJson(CREATOR_INBOX_URL)->assertOk())->keyBy('id');
    expect($rows[$direct['thread']->ulid]['attributes']['unread_count'])->toBe(2)
        ->and($rows[$asg['thread']->ulid]['attributes']['unread_count'])->toBe(1);

    inboxMarkRead(User::query()->findOrFail($creator->user_id), $direct['messages'][0]);
    $after = inboxList($this->actingAs($creator->user)->getJson(CREATOR_INBOX_URL))->keyBy('id');
    expect($after[$direct['thread']->ulid]['attributes']['unread_count'])->toBe(1);

    expect($this->actingAs($creator->user)->getJson(CREATOR_INBOX_URL)->json('meta.unread_threads_total'))->toBe(2);
});

it('filters by direct and by unread, alone and together, and leaves the unread total unfiltered', function (): void {
    creatorInboxFlag();
    ['agency' => $agency, 'admin' => $admin] = inboxWorld(flag: false);
    $creator = inboxCreator('Ada');

    // direct, unread (agency spoke last); assignment, unread; assignment, read (the creator's own line).
    $direct = inboxDirect($agency, $creator, humans: 1, at: now()->subHours(3));
    $direct['messages'][0]->forceFill(['sender_user_id' => $admin->id, 'sender_role' => 'agency_user'])->save();
    $unreadAsg = inboxAssignment($agency, creator: $creator, humans: 1, at: now()->subHours(2));
    Message::query()->whereKey($unreadAsg['messages'][0]->id)->update(['sender_user_id' => $admin->id, 'sender_role' => 'agency_user']);
    $readAsg = inboxAssignment($agency, creator: $creator, humans: 1, at: now()->subHour());

    $ids = fn (string $q) => inboxList($this->actingAs($creator->user)->getJson(CREATOR_INBOX_URL.$q)->assertOk())->pluck('id')->all();

    expect($ids(''))->toHaveCount(3)
        ->and($ids('?direct=1'))->toBe([$direct['thread']->ulid])
        ->and($ids('?unread=1'))->toBe([$unreadAsg['thread']->ulid, $direct['thread']->ulid])
        ->and($ids('?unread=1&direct=1'))->toBe([$direct['thread']->ulid])
        ->and($ids('?unread=0&direct=0'))->toHaveCount(3)
        ->and($ids('?unread=1'))->not->toContain($readAsg['thread']->ulid);

    // The pill is the unfiltered total, whatever the filters.
    expect($this->actingAs($creator->user)->getJson(CREATOR_INBOX_URL.'?direct=1&unread=1')->json('meta.unread_threads_total'))->toBe(2);

    $this->actingAs($creator->user)->getJson(CREATOR_INBOX_URL.'?unread=maybe')->assertUnprocessable();
});

it('keeps another creator\'s threads out of every filter combination', function (): void {
    creatorInboxFlag();
    ['agency' => $agency, 'admin' => $admin] = inboxWorld(flag: false);
    $mine = inboxCreator('Mine');
    $other = inboxCreator('Other');
    $theirs = inboxDirect($agency, $other, humans: 1);
    $theirs['messages'][0]->forceFill(['sender_user_id' => $admin->id, 'sender_role' => 'agency_user'])->save();

    foreach (['', '?direct=1', '?unread=1', '?direct=1&unread=1'] as $q) {
        expect(inboxList($this->actingAs($mine->user)->getJson(CREATOR_INBOX_URL.$q)->assertOk())->all())->toBe([]);
    }
});

it('does not surface a thread that has only system lines', function (): void {
    creatorInboxFlag();
    ['agency' => $agency] = inboxWorld(flag: false);
    $creator = inboxCreator('Ada');
    inboxAssignment($agency, creator: $creator, humans: 0, system: 2);

    expect(inboxList($this->actingAs($creator->user)->getJson(CREATOR_INBOX_URL)->assertOk())->all())->toBe([]);
});

it('pages with a stable cursor and no duplicate or skipped thread', function (): void {
    creatorInboxFlag();
    ['agency' => $agency] = inboxWorld(flag: false);
    $creator = inboxCreator('Ada');
    $same = now()->subHour();
    $expected = [];
    foreach (range(1, 5) as $i) {
        $expected[] = inboxAssignment($agency, creator: $creator, at: $same)['thread']->ulid;
    }
    $expected[] = inboxDirect($agency, $creator, at: $same)['thread']->ulid;

    $seen = [];
    $cursor = null;
    do {
        $url = CREATOR_INBOX_URL.'?per_page=2'.($cursor === null ? '' : '&cursor='.urlencode($cursor));
        $res = $this->actingAs($creator->user)->getJson($url)->assertOk();
        array_push($seen, ...inboxList($res)->pluck('id')->all());
        $cursor = $res->json('meta.next_cursor');
    } while ($cursor !== null);

    expect($seen)->toHaveCount(6)->and(array_unique($seen))->toHaveCount(6)->and($seen)->toEqualCanonicalizing($expected);
});

it('reads a deleted last message as a deleted preview, never older text', function (): void {
    creatorInboxFlag();
    ['agency' => $agency] = inboxWorld(flag: false);
    $creator = inboxCreator('Ada');
    $direct = inboxDirect($agency, $creator, humans: 2);
    inboxDirect($agency, inboxCreator('Pad'));
    $direct['messages'][1]->delete();

    $row = inboxList($this->actingAs($creator->user)->getJson(CREATOR_INBOX_URL)->assertOk())->first();

    expect($row['attributes']['preview_kind'])->toBe('deleted')->and($row['attributes']['preview'])->toBeNull();
});

it('keeps the query count fixed whatever the page size (no N+1)', function (): void {
    creatorInboxFlag();
    ['agency' => $agency] = inboxWorld(flag: false);
    $creator = inboxCreator('Ada');

    inboxAssignment($agency, creator: $creator);
    inboxDirect($agency, $creator);
    $count = function () use ($creator): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($creator->user)->getJson(CREATOR_INBOX_URL)->assertOk();

        return count(DB::getQueryLog());
    };
    $this->actingAs($creator->user)->getJson(CREATOR_INBOX_URL);
    $small = $count();

    foreach (range(1, 8) as $i) {
        inboxAssignment($agency, creator: $creator);
        ['agency' => $other] = inboxWorld(flag: false);
        inboxDirect($other, $creator);
    }
    $large = $count();

    expect($large)->toBe($small);
});

it('requires authentication and a creator profile', function (): void {
    creatorInboxFlag();

    $this->getJson(CREATOR_INBOX_URL)->assertUnauthorized();
    $this->actingAs(User::factory()->createOne())->getJson(CREATOR_INBOX_URL)->assertNotFound();
});

it('searches body text across both tables and is scoped to the creator\'s threads', function (): void {
    creatorInboxFlag();
    ['agency' => $agency] = inboxWorld(flag: false);
    $mine = inboxCreator('Mine');
    $other = inboxCreator('Other');

    $d = inboxDirect($agency, $mine);
    $d['messages'][0]->forceFill(['body' => 'needle in direct'])->save();
    $a = inboxAssignment($agency, creator: $mine);
    $a['messages'][0]->forceFill(['body' => 'needle in campaign'])->save();
    $foreignD = inboxDirect($agency, $other);
    $foreignD['messages'][0]->forceFill(['body' => 'needle foreign direct'])->save();
    $foreignA = inboxAssignment($agency, creator: $other);
    $foreignA['messages'][0]->forceFill(['body' => 'needle foreign campaign'])->save();

    $res = $this->actingAs($mine->user)->getJson(CREATOR_SEARCH_URL.'?q=needle')->assertOk();

    expect($res->json('data.*.attributes.message_ulid'))->toEqualCanonicalizing([$d['messages'][0]->ulid, $a['messages'][0]->ulid]);
    $row = inboxList($res)->first();
    expect($row['attributes'])->toHaveKeys(['snippet', 'sent_at', 'sender_role', 'is_own', 'agency', 'unread_count']);
});

it('search never matches a deleted message, a system line, or text of another creator, and flags is_own', function (): void {
    creatorInboxFlag();
    ['agency' => $agency] = inboxWorld(flag: false);
    $creator = inboxCreator('Ada');

    $direct = inboxDirect($agency, $creator, humans: 2);
    $direct['messages'][0]->forceFill(['body' => 'secretword gone'])->save();
    $direct['messages'][0]->delete();
    $direct['messages'][1]->forceFill(['body' => 'secretword stays'])->save();
    $asg = inboxAssignment($agency, creator: $creator, humans: 1, system: 1);
    Message::query()->whereKey($asg['messages'][1]->id)->update(['body' => 'secretword system']);

    $res = $this->actingAs($creator->user)->getJson(CREATOR_SEARCH_URL.'?q=secretword')->assertOk();

    expect($res->json('data.*.attributes.message_ulid'))->toBe([$direct['messages'][1]->ulid])
        ->and($res->json('data.0.attributes.is_own'))->toBeTrue();
});

it('validates the search query and answers an empty page for stop words', function (): void {
    creatorInboxFlag();
    $creator = inboxCreator('Ada');

    $this->actingAs($creator->user)->getJson(CREATOR_SEARCH_URL)->assertStatus(422);
    $this->actingAs($creator->user)->getJson(CREATOR_SEARCH_URL.'?q=a')->assertStatus(422);
    $this->actingAs($creator->user)->getJson(CREATOR_SEARCH_URL.'?q=%25%25')->assertOk()->assertJsonPath('data', []);
});
