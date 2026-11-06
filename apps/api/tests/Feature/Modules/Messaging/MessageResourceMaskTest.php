<?php

declare(strict_types=1);

use App\Modules\Messaging\Features\MessagesV2InboxEnabled;
use App\Modules\Messaging\Http\Resources\MessageResource;
use App\Modules\Messaging\Http\Resources\RelationshipMessageResource;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\RelationshipMessage;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;
use Laravel\Pennant\Feature;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

require_once dirname(__DIR__, 3).'/Support/MessageRevisionFixtures.php';

/*
 * AH-119 B2 — the resource is the one place that masks: a deleted message has no body, no attachments and
 * mints no signed URL; an edited one carries `edited_at` only. `original_body` and `deleted_body` are
 * never on any resource.
 */

const REV_ATTACHMENT = [['s3_path' => 'messages/t/doc.pdf', 'name' => 'doc.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 10]];

/** @return array<string, mixed> */
function revRender(Message|RelationshipMessage $message): array
{
    $request = Request::create('/');
    $request->setUserResolver(fn () => $message->sender);
    $message->load('sender:id,name');

    return ($message instanceof Message ? new MessageResource($message) : new RelationshipMessageResource($message))->toArray($request);
}

it('masks a deleted message and never mints a signed URL for its attachments', function (string $kind): void {
    $w = revWorld();
    $disk = Mockery::mock(AwsS3V3Adapter::class);
    $disk->shouldReceive('temporaryUrl')->once()->andReturn('https://signed.example/kept.pdf');
    Storage::set('media', $disk);
    $make = fn (): Message|RelationshipMessage => $kind === 'campaign'
        ? Message::factory()->createOne(['thread_id' => $w['thread']->id, 'sender_user_id' => $w['admin']->id, 'body' => 'visible', 'attachments' => REV_ATTACHMENT])
        : RelationshipMessage::factory()->createOne(['thread_id' => $w['direct']->id, 'sender_user_id' => $w['admin']->id, 'body' => 'visible', 'attachments' => REV_ATTACHMENT]);

    // The live one signs exactly once (the mock's `once()`); the deleted one never does.
    $live = revRender($make());
    $deleted = $make();
    $deleted->forceFill(['deleted_body' => 'visible', 'body' => null, 'deleted_at' => now(), 'deleted_by_user_id' => $w['admin']->id, 'original_body' => 'older'])->save();
    $masked = revRender($deleted);

    expect($live['attributes']['attachments'][0]['view_url'])->toBe('https://signed.example/kept.pdf')
        ->and($masked['attributes'])->toMatchArray(['body' => null, 'attachments' => [], 'is_deleted' => true, 'edited_at' => null])
        ->and(array_keys($masked['attributes']))->not->toContain('original_body')->and(array_keys($masked['attributes']))->not->toContain('deleted_body');
    $json = (string) json_encode($masked);
    expect($json)->not->toContain('visible')->and($json)->not->toContain('older')->and($json)->not->toContain('doc.pdf');
})->with(['campaign', 'direct']);

it('shows an edited message with edited_at only: no original, no flag', function (string $kind): void {
    $w = revWorld();
    $message = $kind === 'campaign'
        ? Message::factory()->createOne(['thread_id' => $w['thread']->id, 'sender_user_id' => $w['admin']->id, 'body' => 'new text', 'original_body' => 'old text', 'edited_at' => now()])
        : RelationshipMessage::factory()->createOne(['thread_id' => $w['direct']->id, 'sender_user_id' => $w['admin']->id, 'body' => 'new text', 'original_body' => 'old text', 'edited_at' => now()]);

    $out = revRender($message);

    expect($out['attributes']['body'])->toBe('new text')
        ->and($out['attributes']['is_deleted'])->toBeFalse()
        ->and($out['attributes']['edited_at'])->toBeString()
        ->and((string) json_encode($out))->not->toContain('old text');
    expect((string) json_encode($out))->not->toContain('original_body');
})->with(['campaign', 'direct']);

it('always carries the two additive fields, null and false on an untouched message', function (string $kind): void {
    $w = revWorld(flag: false);
    $message = $kind === 'campaign'
        ? Message::factory()->createOne(['thread_id' => $w['thread']->id, 'sender_user_id' => $w['admin']->id])
        : RelationshipMessage::factory()->createOne(['thread_id' => $w['direct']->id, 'sender_user_id' => $w['admin']->id]);

    expect(revRender($message)['attributes'])->toMatchArray(['edited_at' => null, 'is_deleted' => false]);
})->with(['campaign', 'direct']);

it('never leaks withdrawn text through any list endpoint: feeds, rollup, inboxes', function (): void {
    $w = revWorld();
    $m = revCampaignMessage($w['thread'], $w['creatorUser'], true, 'rollup-secret-text');
    $d = revDirectMessage($w['direct'], $w['creatorUser'], true, 'inbox-secret-text');
    foreach ([[$m, 'creator-campaign'], [$d, 'creator-direct']] as [$message, $surface]) {
        $this->actingAs($w['creatorUser'])->deleteJson(revUrls($w, $message->ulid)[$surface])->assertOk();
    }

    $responses = [
        $this->actingAs($w['admin'])->getJson("/api/v1/agencies/{$w['agency']->ulid}/campaigns/{$w['campaign']->ulid}/message-threads"),
        $this->actingAs($w['admin'])->getJson("/api/v1/agencies/{$w['agency']->ulid}/relationship-threads"),
        $this->actingAs($w['creatorUser'])->getJson('/api/v1/creators/me/relationship-threads'),
        $this->actingAs($w['admin'])->getJson("/api/v1/agencies/{$w['agency']->ulid}/creators/{$w['creator']->ulid}/relationship-messages"),
    ];

    foreach ($responses as $response) {
        $response->assertOk();
        expect($response->getContent())->not->toContain('secret-text');
    }
    // The thread rows survive with the placeholder flag and no text (a thread of only deleted messages stays listed here).
    expect($responses[0]->json('data.0.attributes.last_message_is_deleted'))->toBeTrue()
        ->and($responses[0]->json('data.0.attributes.last_message_preview'))->toBeNull()
        ->and($responses[1]->json('data.0.attributes.last_message_is_deleted'))->toBeTrue()
        ->and($responses[1]->json('data.0.attributes.last_message_preview'))->toBeNull()
        ->and($responses[2]->json('data.0.attributes.last_message_is_deleted'))->toBeTrue();
});

it('previews a deleted last message as `deleted` in the unified inbox, never falling back to older text', function (): void {
    Date::setTestNow();
    Feature::activate(MessagesV2InboxEnabled::NAME);
    $w = revWorld();
    revDirectMessage($w['direct'], $w['creatorUser'], true, 'older, still visible');
    $last = revDirectMessage($w['direct'], $w['creatorUser'], true, 'newest, withdrawn');
    revCampaignMessage($w['thread'], $w['creatorUser'], true, 'campaign only message');
    $w['direct']->forceFill(['last_message_at' => now()])->save();

    $inbox = fn () => $this->actingAs($w['admin'])->getJson("/api/v1/agencies/{$w['agency']->ulid}/message-inbox")->assertOk();
    $row = function ($response, string $type) {
        /** @var list<array<string, mixed>> $data */
        $data = $response->json('data');

        return collect($data)->firstWhere('type', $type);
    };

    expect(data_get($row($inbox(), 'relationship_thread'), 'attributes'))->toMatchArray(['preview' => 'newest, withdrawn', 'preview_kind' => 'text']);

    $this->actingAs($w['creatorUser'])->deleteJson(revUrls($w, $last->ulid)['creator-direct'])->assertOk();
    $after = $inbox();

    expect(data_get($row($after, 'relationship_thread'), 'attributes'))->toMatchArray(['preview' => null, 'preview_kind' => 'deleted'])
        ->and(data_get($row($after, 'assignment_thread'), 'attributes'))->toMatchArray(['preview' => 'campaign only message', 'preview_kind' => 'text'])
        ->and($after->getContent())->not->toContain('newest, withdrawn');
});

it('drops a thread whose human messages are ALL deleted from the unified inbox (Q2), and keeps the older lists', function (): void {
    Feature::activate(MessagesV2InboxEnabled::NAME);
    $w = revWorld();
    $only = revDirectMessage($w['direct'], $w['creatorUser'], true, 'the only one');
    $this->actingAs($w['creatorUser'])->deleteJson(revUrls($w, $only->ulid)['creator-direct'])->assertOk();

    $this->actingAs($w['admin'])->getJson("/api/v1/agencies/{$w['agency']->ulid}/message-inbox")->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($w['admin'])->getJson("/api/v1/agencies/{$w['agency']->ulid}/relationship-threads")->assertOk()->assertJsonCount(1, 'data');
    $this->actingAs($w['creatorUser'])->getJson('/api/v1/creators/me/relationship-threads')->assertOk()->assertJsonCount(1, 'data');
});
