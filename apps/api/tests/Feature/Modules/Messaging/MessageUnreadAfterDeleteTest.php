<?php

declare(strict_types=1);

use App\Modules\Messaging\Features\MessagesV2InboxEnabled;
use App\Modules\Messaging\Models\MessageReadReceipt;
use App\Modules\Messaging\Models\RelationshipMessageReadReceipt;
use App\Modules\Notifications\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

require_once dirname(__DIR__, 3).'/Support/MessageRevisionFixtures.php';

/*
 * AH-119 B2 / Q3 — a deleted message drops out of the recipient's unread (the SoftDeletes scope reads
 * it out of the unread query), while read receipts and notifications are left exactly as they were.
 */

it('lowers the agency\'s unread count in the campaign thread feed and the unified inbox when the creator deletes', function (): void {
    Feature::activate(MessagesV2InboxEnabled::NAME);
    $w = revWorld();
    revCampaignMessage($w['thread'], $w['creatorUser'], true, 'one');
    $second = revCampaignMessage($w['thread'], $w['creatorUser'], true, 'two');
    $feedUrl = "/api/v1/agencies/{$w['agency']->ulid}/campaigns/{$w['campaign']->ulid}/assignments/{$w['assignment']->ulid}/messages";
    $inboxUrl = "/api/v1/agencies/{$w['agency']->ulid}/message-inbox";

    expect($this->actingAs($w['admin'])->getJson($feedUrl)->json('meta.thread.unread_count'))->toBe(2)
        ->and($this->actingAs($w['admin'])->getJson($inboxUrl)->json('data.0.attributes.unread_count'))->toBe(2);

    $receipts = MessageReadReceipt::query()->count();
    $notifications = Notification::query()->count();
    $this->actingAs($w['creatorUser'])->deleteJson(revUrls($w, $second->ulid)['creator-campaign'])->assertOk();

    expect($this->actingAs($w['admin'])->getJson($feedUrl)->json('meta.thread.unread_count'))->toBe(1)
        ->and($this->actingAs($w['admin'])->getJson($inboxUrl)->json('data.0.attributes.unread_count'))->toBe(1)
        ->and(MessageReadReceipt::query()->count())->toBe($receipts)
        ->and(Notification::query()->count())->toBe($notifications);
});

it('lowers the unread count of a direct thread on both sides when the sender deletes', function (): void {
    $w = revWorld();
    revDirectMessage($w['direct'], $w['creatorUser'], true, 'to the agency one');
    $toAgency = revDirectMessage($w['direct'], $w['creatorUser'], true, 'to the agency two');
    $toCreator = revDirectMessage($w['direct'], $w['admin'], false, 'to the creator');
    $agencyFeed = "/api/v1/agencies/{$w['agency']->ulid}/creators/{$w['creator']->ulid}/relationship-messages";
    $creatorFeed = "/api/v1/creators/me/relationship-threads/{$w['agency']->ulid}/messages";

    expect($this->actingAs($w['admin'])->getJson($agencyFeed)->json('meta.thread.unread_count'))->toBe(2)
        ->and($this->actingAs($w['creatorUser'])->getJson($creatorFeed)->json('meta.thread.unread_count'))->toBe(1);

    $receipts = RelationshipMessageReadReceipt::query()->count();
    $this->actingAs($w['creatorUser'])->deleteJson(revUrls($w, $toAgency->ulid)['creator-direct'])->assertOk();
    $this->actingAs($w['admin'])->deleteJson(revUrls($w, $toCreator->ulid)['agency-direct'])->assertOk();

    expect($this->actingAs($w['admin'])->getJson($agencyFeed)->json('meta.thread.unread_count'))->toBe(1)
        ->and($this->actingAs($w['creatorUser'])->getJson($creatorFeed)->json('meta.thread.unread_count'))->toBe(0)
        ->and(RelationshipMessageReadReceipt::query()->count())->toBe($receipts);
});

it('writes no receipt for a deleted message when the thread is marked read, and leaves earlier receipts alone', function (): void {
    $w = revWorld();
    $kept = revCampaignMessage($w['thread'], $w['creatorUser'], true, 'kept');
    $gone = revCampaignMessage($w['thread'], $w['creatorUser'], true, 'gone');
    $this->actingAs($w['creatorUser'])->deleteJson(revUrls($w, $gone->ulid)['creator-campaign'])->assertOk();

    $this->actingAs($w['admin'])->postJson("/api/v1/agencies/{$w['agency']->ulid}/campaigns/{$w['campaign']->ulid}/assignments/{$w['assignment']->ulid}/messages/read")
        ->assertOk()->assertJsonPath('meta.marked', 1);

    expect(MessageReadReceipt::query()->pluck('message_id')->all())->toBe([$kept->id]);
});

it('keeps the receipts of a message that was read and is deleted afterwards', function (): void {
    $w = revWorld();
    $message = revDirectMessage($w['direct'], $w['creatorUser'], true, 'read then withdrawn');
    $this->actingAs($w['admin'])->postJson("/api/v1/agencies/{$w['agency']->ulid}/creators/{$w['creator']->ulid}/relationship-messages/read")->assertOk();
    expect(RelationshipMessageReadReceipt::query()->count())->toBe(1);

    $this->actingAs($w['creatorUser'])->deleteJson(revUrls($w, $message->ulid)['creator-direct'])->assertOk();

    expect(RelationshipMessageReadReceipt::query()->count())->toBe(1);
});
