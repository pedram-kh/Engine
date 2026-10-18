<?php

declare(strict_types=1);

use App\Core\Tenancy\TenancyContext;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageReadReceipt;
use App\Modules\Messaging\Models\MessageThread;
use App\Modules\Messaging\Models\RelationshipMessage;
use App\Modules\Messaging\Models\RelationshipMessageReadReceipt;
use App\Modules\Messaging\Models\RelationshipThread;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| AH-108 — `php artisan notifications:clear-read-message-notifications`
|--------------------------------------------------------------------------
|
| The one-shot backlog clean-up (Q1, option A). Marks an unread message
| notification read when its thread has no unread messages for its recipient.
| Over-reach negatives: a notification whose thread still has something unread
| stays unread, non-message types are never touched, an unplaceable row is left
| alone, --dry-run writes nothing and reports the same count the real run marks,
| and a re-run marks nothing.
*/

/**
 * One campaign chat with a message from the agency to the creator.
 *
 * @return array{assignment: CampaignAssignment, thread: MessageThread, message: Message, creatorUser: User, admin: User}
 */
function crmCampaignChat(): array
{
    // A real request is a fresh app; the TenancyContext singleton would otherwise
    // keep the previous chat's agency for the next send.
    app(TenancyContext::class)->forget();
    app('auth')->forgetGuards();

    $agency = Agency::factory()->createOne();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();
    $campaign = Campaign::factory()->createOne(['agency_id' => $agency->id, 'brand_id' => $brand->id]);
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $creatorUser = User::factory()->createOne();
    $creator = CreatorFactory::new()->createOne(['user_id' => $creatorUser->id]);

    $assignment = CampaignAssignment::factory()->status(AssignmentStatus::Contracted)->createOne([
        'agency_id' => $agency->id,
        'campaign_id' => $campaign->id,
        'brand_id' => $brand->id,
        'creator_id' => $creator->id,
        'invited_by_user_id' => $admin->id,
    ]);

    // Send through the API so the thread, message and (pre-existing-shape)
    // notification are the ones production creates.
    test()->actingAs($admin)
        ->postJson("/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}/assignments/{$assignment->ulid}/messages", ['body' => 'hello'])
        ->assertCreated();

    $thread = MessageThread::withoutGlobalScopes()->where('assignment_id', $assignment->id)->firstOrFail();
    $message = Message::query()->where('thread_id', $thread->id)->firstOrFail();

    return compact('assignment', 'thread', 'message', 'creatorUser', 'admin');
}

/** The creator's single unread message.received_by_creator row for a chat. */
function crmCreatorRow(array $chat): Notification
{
    return Notification::query()
        ->where('recipient_user_id', $chat['creatorUser']->id)
        ->where('type', NotificationType::MessageReceivedByCreator->value)
        ->where('subject_id', $chat['assignment']->id)
        ->firstOrFail();
}

/** Receipts only, the way the pre-AH-108 /read left things: message read, notification not. */
function crmReadMessageOnly(array $chat): void
{
    MessageReadReceipt::query()->insertOrIgnore([[
        'message_id' => $chat['message']->id,
        'user_id' => $chat['creatorUser']->id,
        'read_at' => now(),
    ]]);
}

/**
 * @return array{agency: Agency, creator: Creator, creatorUser: User, admin: User, thread: RelationshipThread, message: RelationshipMessage}
 */
function crmRelationshipChat(): array
{
    app(TenancyContext::class)->forget();
    app('auth')->forgetGuards();

    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $creatorUser = User::factory()->createOne();
    $creator = CreatorFactory::new()->approved()->createOne(['user_id' => $creatorUser->id]);
    AgencyCreatorRelation::factory()->createOne([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::Roster,
        'is_blacklisted' => false,
    ]);

    test()->actingAs($admin)
        ->postJson("/api/v1/agencies/{$agency->ulid}/creators/{$creator->ulid}/relationship-messages", ['body' => 'hi'])
        ->assertCreated();

    $thread = RelationshipThread::withoutGlobalScopes()
        ->where('agency_id', $agency->id)->where('creator_id', $creator->id)->firstOrFail();
    $message = RelationshipMessage::query()->where('thread_id', $thread->id)->firstOrFail();

    return compact('agency', 'creator', 'creatorUser', 'admin', 'thread', 'message');
}

/** @return array{int, string} */
function crmRun(bool $dryRun = false): array
{
    // Artisan runs with NO tenancy context, exactly as on the server.
    app(TenancyContext::class)->forget();

    $code = Artisan::call('notifications:clear-read-message-notifications', $dryRun ? ['--dry-run' => true] : []);

    return [$code, Artisan::output()];
}

it('marks a campaign-chat notification read when its messages are already read', function (): void {
    $chat = crmCampaignChat();
    $row = crmCreatorRow($chat);
    crmReadMessageOnly($chat);

    [$code, $out] = crmRun();

    expect($code)->toBe(0)
        ->and($row->fresh()?->read_at)->not->toBeNull()
        ->and($out)->toContain('Processed 1 unread message notification(s); 1 marked read, 0 left unread');
});

it('leaves a campaign-chat notification unread while its thread still has an unread message', function (): void {
    $chat = crmCampaignChat();
    $row = crmCreatorRow($chat);   // the creator has NOT read the message

    [, $out] = crmRun();

    expect($row->fresh()?->read_at)->toBeNull()
        ->and($out)->toContain('0 marked read, 1 left unread');
});

it('judges each recipient by their OWN receipts, not a colleague\'s', function (): void {
    $chat = crmCampaignChat();
    $row = crmCreatorRow($chat);

    // Someone else reading the message must not clear the creator's notification.
    MessageReadReceipt::query()->insertOrIgnore([[
        'message_id' => $chat['message']->id,
        'user_id' => $chat['admin']->id,
        'read_at' => now(),
    ]]);

    crmRun();

    expect($row->fresh()?->read_at)->toBeNull();
});

it('marks a relationship-chat notification read when its messages are already read', function (): void {
    $chat = crmRelationshipChat();
    $row = Notification::query()
        ->where('recipient_user_id', $chat['creatorUser']->id)
        ->where('type', NotificationType::MessageRelationshipReceivedByCreator->value)
        ->firstOrFail();
    RelationshipMessageReadReceipt::query()->insertOrIgnore([[
        'message_id' => $chat['message']->id,
        'user_id' => $chat['creatorUser']->id,
        'read_at' => now(),
    ]]);

    crmRun();

    expect($row->fresh()?->read_at)->not->toBeNull();
});

it('leaves a relationship-chat notification unread while the thread has an unread message', function (): void {
    $chat = crmRelationshipChat();
    $row = Notification::query()
        ->where('recipient_user_id', $chat['creatorUser']->id)
        ->where('type', NotificationType::MessageRelationshipReceivedByCreator->value)
        ->firstOrFail();

    crmRun();

    expect($row->fresh()?->read_at)->toBeNull();
});

it('never touches a non-message notification, even on a thread that was read', function (): void {
    // On a read campaign chat …
    $chat = crmCampaignChat();
    crmReadMessageOnly($chat);
    $onAssignment = Notification::factory()->ofType(NotificationType::AssignmentAccepted)->createOne([
        'recipient_user_id' => $chat['creatorUser']->id,
        'subject_type' => $chat['assignment']->getMorphClass(),
        'subject_id' => $chat['assignment']->id,
    ]);

    // … and on a read relationship thread (a different code path in the command).
    $rel = crmRelationshipChat();
    RelationshipMessageReadReceipt::query()->insertOrIgnore([[
        'message_id' => $rel['message']->id,
        'user_id' => $rel['creatorUser']->id,
        'read_at' => now(),
    ]]);
    $onThread = Notification::factory()->ofType(NotificationType::AssignmentAccepted)->createOne([
        'recipient_user_id' => $rel['creatorUser']->id,
        'subject_type' => $rel['thread']->getMorphClass(),
        'subject_id' => $rel['thread']->id,
    ]);

    crmRun();

    expect($onAssignment->fresh()?->read_at)->toBeNull()
        ->and($onThread->fresh()?->read_at)->toBeNull();
});

it('does not clear a relationship row whose subject is another kind that shares a thread\'s id', function (): void {
    $rel = crmRelationshipChat();
    RelationshipMessageReadReceipt::query()->insertOrIgnore([[
        'message_id' => $rel['message']->id,
        'user_id' => $rel['creatorUser']->id,
        'read_at' => now(),
    ]]);
    // Ids collide across tables: this row points at an AGENCY that happens to have the
    // read thread's id. Without the subject_type check it would be judged by that thread.
    $collision = Notification::factory()->ofType(NotificationType::MessageRelationshipReceivedByCreator)->createOne([
        'recipient_user_id' => $rel['creatorUser']->id,
        'subject_type' => (new Agency)->getMorphClass(),
        'subject_id' => $rel['thread']->id,
    ]);

    crmRun();

    expect($collision->fresh()?->read_at)->toBeNull();
});

it('leaves a row it cannot place unread and counts it separately', function (): void {
    $user = User::factory()->createOne();
    // A message-type row whose subject is not the kind that type points at, one with
    // no subject, and one whose assignment has no thread.
    $wrongKind = Notification::factory()->ofType(NotificationType::MessageReceivedByCreator)->createOne([
        'recipient_user_id' => $user->id, 'subject_type' => (new Agency)->getMorphClass(), 'subject_id' => 1,
    ]);
    $noSubject = Notification::factory()->ofType(NotificationType::MessageRelationshipReceivedByCreator)->createOne([
        'recipient_user_id' => $user->id,
    ]);
    $noThread = Notification::factory()->ofType(NotificationType::MessageReceivedByCreator)->createOne([
        'recipient_user_id' => $user->id, 'subject_type' => (new CampaignAssignment)->getMorphClass(), 'subject_id' => 999_999,
    ]);

    [$code, $out] = crmRun();

    expect($code)->toBe(0)
        ->and($wrongKind->fresh()?->read_at)->toBeNull()
        ->and($noSubject->fresh()?->read_at)->toBeNull()
        ->and($noThread->fresh()?->read_at)->toBeNull()
        ->and($out)->toContain('0 marked read, 0 left unread (thread still has unread messages), 3 could not be placed');
});

it('--dry-run writes nothing and reports the same number the real run then marks', function (): void {
    $stale = crmCampaignChat();
    crmReadMessageOnly($stale);
    $staleRow = crmCreatorRow($stale);
    $live = crmCampaignChat();   // unread message: must not be counted

    [, $dry] = crmRun(dryRun: true);

    expect($staleRow->fresh()?->read_at)->toBeNull()
        ->and($dry)->toContain('Checked 2 unread message notification(s); 1 would be marked read, 1 left unread');

    [, $real] = crmRun();

    expect($staleRow->fresh()?->read_at)->not->toBeNull()
        ->and($real)->toContain('Processed 2 unread message notification(s); 1 marked read, 1 left unread');
});

it('is idempotent: a second run finds nothing to mark and moves no timestamp', function (): void {
    $chat = crmCampaignChat();
    crmReadMessageOnly($chat);
    $row = crmCreatorRow($chat);

    crmRun();
    $first = $row->fresh()?->read_at;

    $this->travel(5)->minutes();
    [, $second] = crmRun();

    expect($row->fresh()?->read_at?->equalTo($first))->toBeTrue()
        ->and($second)->toContain('Processed 0 unread message notification(s); 0 marked read');
});
