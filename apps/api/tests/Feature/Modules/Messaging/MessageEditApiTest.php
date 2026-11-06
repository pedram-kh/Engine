<?php

declare(strict_types=1);

use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\RelationshipMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

require_once dirname(__DIR__, 3).'/Support/MessageRevisionFixtures.php';

/*
 * AH-119 B2 — edit of one's own message, on all four route sets (agency and creator, campaign and
 * direct). The same assertions run on every surface through the dataset, so no surface can drift.
 */

it('edits the sender\'s own message: 200, the updated resource, the original kept, the thread not moved', function (string $surface): void {
    $w = revWorld();
    $thread = $surface === 'agency-campaign' || $surface === 'creator-campaign' ? $w['thread'] : $w['direct'];
    $thread->forceFill(['last_message_at' => now()->subHour()])->save();
    $before = $thread->refresh()->last_message_at?->toIso8601String();
    $s = revSurface($w, $surface, 'first draft');

    $response = $this->actingAs($s['actor'])->patchJson($s['url'], ['body' => 'second draft'])->assertOk();

    $response->assertJsonPath('data.id', $s['message']->ulid)
        ->assertJsonPath('data.type', $s['type'])
        ->assertJsonPath('data.attributes.body', 'second draft')
        ->assertJsonPath('data.attributes.is_deleted', false)
        ->assertJsonPath('data.attributes.is_own', true);
    expect($response->json('data.attributes.edited_at'))->not->toBeNull()
        ->and(json_encode($response->json()))->not->toContain('first draft')
        ->and(json_encode($response->json()))->not->toContain('original_body');

    $row = revRow($s['table'], $s['message']->ulid);
    expect($row->body)->toBe('second draft')
        ->and($row->original_body)->toBe('first draft')
        ->and($row->edited_at)->not->toBeNull()
        ->and($thread->refresh()->last_message_at?->toIso8601String())->toBe($before);
})->with(revSurfaceDataset());

it('captures the original on the FIRST edit only', function (string $surface): void {
    $w = revWorld();
    $s = revSurface($w, $surface, 'v1');

    $this->actingAs($s['actor'])->patchJson($s['url'], ['body' => 'v2'])->assertOk();
    $this->actingAs($s['actor'])->patchJson($s['url'], ['body' => 'v3'])->assertOk();

    $row = revRow($s['table'], $s['message']->ulid);
    expect($row->body)->toBe('v3')->and($row->original_body)->toBe('v1');
})->with(revSurfaceDataset());

it('treats an identical body as a 200 no-op: nothing written', function (string $surface): void {
    $w = revWorld();
    $s = revSurface($w, $surface, 'same text');

    $this->actingAs($s['actor'])->patchJson($s['url'], ['body' => 'same text'])
        ->assertOk()->assertJsonPath('data.attributes.edited_at', null);

    $row = revRow($s['table'], $s['message']->ulid);
    expect($row->edited_at)->toBeNull()->and($row->original_body)->toBeNull();
})->with(revSurfaceDataset());

it('validates the body: required, trimmed, 1 to 5000 characters', function (string $surface, mixed $body): void {
    $w = revWorld();
    $s = revSurface($w, $surface);

    $this->actingAs($s['actor'])->patchJson($s['url'], ['body' => $body])->assertUnprocessable();

    expect(revRow($s['table'], $s['message']->ulid)->body)->toBe('original text');
})->with(revSurfaceDataset())->with(['empty' => [''], 'spaces only' => ['   '], 'missing' => [null], 'too long' => [str_repeat('x', 5001)], 'not a string' => [['a']]]);

it('accepts exactly 5000 characters and trims the stored text', function (): void {
    $w = revWorld();
    $s = revSurface($w, 'agency-direct');

    $this->actingAs($s['actor'])->patchJson($s['url'], ['body' => '  '.str_repeat('é', 5000).'  '])->assertOk();

    expect(mb_strlen((string) revRow($s['table'], $s['message']->ulid)->body))->toBe(5000);
});

it('refuses an attachment-only message: message.not_editable', function (string $surface): void {
    $w = revWorld();
    $isCampaign = str_contains($surface, 'campaign');
    $actor = str_starts_with($surface, 'agency') ? $w['admin'] : $w['creatorUser'];
    $attachments = [['s3_path' => 'messages/x/file.pdf', 'name' => 'file.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 10]];
    $message = $isCampaign
        ? Message::factory()->attachmentOnly($attachments)->createOne(['thread_id' => $w['thread']->id, 'sender_user_id' => $actor->id])
        : RelationshipMessage::factory()->attachmentOnly($attachments)->createOne(['thread_id' => $w['direct']->id, 'sender_user_id' => $actor->id]);

    $this->actingAs($actor)->patchJson(revUrls($w, $message->ulid)[$surface], ['body' => 'now with text'])
        ->assertUnprocessable()->assertJsonPath('errors.0.code', 'message.not_editable');

    expect(DB::table($isCampaign ? 'messages' : 'relationship_messages')->where('id', $message->id)->value('body'))->toBeNull();
})->with(revSurfaceDataset());

it('keeps an edited message\'s attachments and kind untouched', function (): void {
    $w = revWorld();
    $attachments = [['s3_path' => 'messages/x/file.pdf', 'name' => 'file.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 10]];
    $message = Message::factory()->createOne(['thread_id' => $w['thread']->id, 'sender_user_id' => $w['admin']->id, 'body' => 'with a file', 'attachments' => $attachments]);

    $this->actingAs($w['admin'])->patchJson(revUrls($w, $message->ulid)['agency-campaign'], ['body' => 'with a file, fixed'])
        ->assertOk()->assertJsonPath('data.attributes.attachments.0.name', 'file.pdf');

    expect($message->refresh()->attachments)->toEqual($attachments);
});

it('refuses an edit in a closed thread: message.edit_thread_closed (Q12)', function (string $surface): void {
    $w = revWorld(status: AssignmentStatus::Cancelled);
    // A closed direct thread: the relation is blacklisted, so the send gate (canMessageRelationship) is false.
    DB::table('agency_creator_relations')->where('agency_id', $w['agency']->id)->update(['is_blacklisted' => true]);
    $s = revSurface($w, $surface);

    $this->actingAs($s['actor'])->patchJson($s['url'], ['body' => 'late fix'])
        ->assertUnprocessable()->assertJsonPath('errors.0.code', 'message.edit_thread_closed');

    $row = revRow($s['table'], $s['message']->ulid);
    expect($row->body)->toBe('original text')->and($row->edited_at)->toBeNull();
})->with(revSurfaceDataset());

it('edits again once a closed thread is reopened (a refused edit leaves no trace)', function (): void {
    $w = revWorld(status: AssignmentStatus::Declined);
    $s = revSurface($w, 'creator-campaign');

    $this->actingAs($s['actor'])->patchJson($s['url'], ['body' => 'x'])->assertUnprocessable();
    $w['assignment']->forceFill(['status' => AssignmentStatus::Accepted])->save();

    $this->actingAs($s['actor'])->patchJson($s['url'], ['body' => 'x'])->assertOk();
});
