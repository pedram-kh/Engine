<?php

declare(strict_types=1);

use App\Modules\Campaigns\Enums\AssignmentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

require_once dirname(__DIR__, 3).'/Support/MessageRevisionFixtures.php';

/*
 * AH-119 B2 — delete of one's own message on all four route sets: the body moves to `deleted_body`, the row
 * and the attachments stay, the response and the feed show the mask, and the thread does not move.
 */

it('deletes the sender\'s own message: masked response, body moved, attachments kept, thread not moved', function (string $surface): void {
    $w = revWorld();
    $thread = str_contains($surface, 'campaign') ? $w['thread'] : $w['direct'];
    $thread->forceFill(['last_message_at' => now()->subHour()])->save();
    $before = $thread->refresh()->last_message_at?->toIso8601String();
    $s = revSurface($w, $surface, 'to be withdrawn');
    $attachments = [['s3_path' => "messages/{$thread->ulid}/doc.pdf", 'name' => 'doc.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 10]];
    DB::table($s['table'])->where('id', $s['message']->id)->update(['attachments' => json_encode($attachments)]);

    $response = $this->actingAs($s['actor'])->deleteJson($s['url'])->assertOk();

    $response->assertJsonPath('data.id', $s['message']->ulid)
        ->assertJsonPath('data.type', $s['type'])
        ->assertJsonPath('data.attributes.body', null)
        ->assertJsonPath('data.attributes.attachments', [])
        ->assertJsonPath('data.attributes.is_deleted', true)
        ->assertJsonPath('data.attributes.edited_at', null);
    $json = (string) json_encode($response->json());
    expect($json)->not->toContain('to be withdrawn')->and($json)->not->toContain('doc.pdf')->and($json)->not->toContain('deleted_body');

    $row = revRow($s['table'], $s['message']->ulid);
    expect($row->body)->toBeNull()
        ->and($row->deleted_body)->toBe('to be withdrawn')
        ->and($row->deleted_at)->not->toBeNull()
        ->and((int) $row->deleted_by_user_id)->toBe($s['actor']->id)
        ->and(json_decode((string) $row->attachments, true))->toEqual($attachments)
        ->and($thread->refresh()->last_message_at?->toIso8601String())->toBe($before);
})->with(revSurfaceDataset());

it('allows a delete in a closed thread (Q12) and after the 15-minute window', function (string $surface): void {
    $w = revWorld(status: AssignmentStatus::Rejected);
    DB::table('agency_creator_relations')->where('agency_id', $w['agency']->id)->update(['relationship_status' => 'ended']);
    Carbon::setTestNow(now()->subHours(3));
    $s = revSurface($w, $surface);
    Carbon::setTestNow();

    $this->actingAs($s['actor'])->deleteJson($s['url'])->assertOk()->assertJsonPath('data.attributes.is_deleted', true);

    expect(revRow($s['table'], $s['message']->ulid)->deleted_at)->not->toBeNull();
})->with(revSurfaceDataset());

it('refuses a second delete and an edit of a deleted message: message.already_deleted', function (string $surface): void {
    $w = revWorld();
    $s = revSurface($w, $surface);
    $this->actingAs($s['actor'])->deleteJson($s['url'])->assertOk();

    $this->actingAs($s['actor'])->deleteJson($s['url'])->assertUnprocessable()->assertJsonPath('errors.0.code', 'message.already_deleted');
    $this->actingAs($s['actor'])->patchJson($s['url'], ['body' => 'undo'])->assertUnprocessable()->assertJsonPath('errors.0.code', 'message.already_deleted');

    $row = revRow($s['table'], $s['message']->ulid);
    expect($row->body)->toBeNull()->and($row->deleted_body)->toBe('original text');
})->with(revSurfaceDataset());

it('keeps the first original and the deleted text separately when an edited message is deleted', function (): void {
    $w = revWorld();
    $s = revSurface($w, 'agency-campaign', 'v1');
    $this->actingAs($s['actor'])->patchJson($s['url'], ['body' => 'v2'])->assertOk();

    $this->actingAs($s['actor'])->deleteJson($s['url'])->assertOk();

    $row = revRow('messages', $s['message']->ulid);
    expect($row->original_body)->toBe('v1')->and($row->deleted_body)->toBe('v2')->and($row->body)->toBeNull();
});

it('shows a deleted message in the feed, in place and masked, on both sides', function (string $surface): void {
    $w = revWorld();
    $s = revSurface($w, $surface, 'secret words');
    $other = str_starts_with($surface, 'agency') ? 'creator' : 'agency';
    $this->actingAs($s['actor'])->deleteJson($s['url'])->assertOk();

    $feeds = [
        'agency-campaign' => ["/api/v1/agencies/{$w['agency']->ulid}/campaigns/{$w['campaign']->ulid}/assignments/{$w['assignment']->ulid}/messages", $w['admin']],
        'agency-direct' => ["/api/v1/agencies/{$w['agency']->ulid}/creators/{$w['creator']->ulid}/relationship-messages", $w['admin']],
        'creator-campaign' => ["/api/v1/creators/me/assignments/{$w['assignment']->ulid}/messages", $w['creatorUser']],
        'creator-direct' => ["/api/v1/creators/me/relationship-threads/{$w['agency']->ulid}/messages", $w['creatorUser']],
    ];
    $sameKind = str_contains($surface, 'campaign') ? ['agency-campaign', 'creator-campaign'] : ['agency-direct', 'creator-direct'];
    foreach ($sameKind as $name) {
        [$url, $viewer] = $feeds[$name];
        $feed = $this->actingAs($viewer)->getJson($url)->assertOk();
        /** @var list<array<string, mixed>> $data */
        $data = $feed->json('data');
        $row = collect($data)->firstWhere('id', $s['message']->ulid);

        expect($row)->not->toBeNull()
            ->and(data_get($row, 'attributes.body'))->toBeNull()
            ->and(data_get($row, 'attributes.attachments'))->toBe([])
            ->and(data_get($row, 'attributes.is_deleted'))->toBeTrue()
            ->and($feed->getContent())->not->toContain('secret words');
    }
    expect($other)->toBeString();
})->with(revSurfaceDataset());

it('lets a delete page back through history: a deleted message is a valid `before` cursor', function (): void {
    $w = revWorld();
    $first = revCampaignMessage($w['thread'], $w['admin'], false, 'one');
    $second = revCampaignMessage($w['thread'], $w['admin'], false, 'two');
    $this->actingAs($w['admin'])->deleteJson(revUrls($w, $second->ulid)['agency-campaign'])->assertOk();

    $feed = $this->actingAs($w['admin'])->getJson("/api/v1/agencies/{$w['agency']->ulid}/campaigns/{$w['campaign']->ulid}/assignments/{$w['assignment']->ulid}/messages?before={$second->ulid}")->assertOk();

    /** @var list<array<string, mixed>> $data */
    $data = $feed->json('data');
    expect(collect($data)->pluck('id')->all())->toBe([$first->ulid]);
});
