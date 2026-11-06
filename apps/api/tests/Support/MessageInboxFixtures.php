<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Enums\MessageSenderRole;
use App\Modules\Messaging\Features\MessagesV2InboxEnabled;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageReadReceipt;
use App\Modules\Messaging\Models\MessageThread;
use App\Modules\Messaging\Models\RelationshipMessage;
use App\Modules\Messaging\Models\RelationshipMessageReadReceipt;
use App\Modules\Messaging\Models\RelationshipThread;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\Response;

/*
|--------------------------------------------------------------------------
| AH-119 — shared fixtures for the unified-inbox specs
|--------------------------------------------------------------------------
| Plain functions (the repo's Pest idiom), loaded with require_once.
*/

/** @return array{agency: Agency, admin: User, manager: User, staff: User} */
function inboxWorld(bool $flag = true): array
{
    $flag ? Feature::activate(MessagesV2InboxEnabled::NAME) : Feature::deactivate(MessagesV2InboxEnabled::NAME);

    $agency = Agency::factory()->createOne();

    return [
        'agency' => $agency,
        'admin' => User::factory()->agencyAdmin($agency)->createOne(),
        'manager' => User::factory()->agencyManager($agency)->createOne(),
        'staff' => User::factory()->agencyStaff($agency)->createOne(),
    ];
}

function inboxUrl(Agency $agency, string $query = ''): string
{
    return "/api/v1/agencies/{$agency->ulid}/message-inbox".($query === '' ? '' : '?'.$query);
}

function inboxCreator(string $name = 'Creator'): Creator
{
    return CreatorFactory::new()->approved()->createOne([
        'user_id' => User::factory()->createOne()->id,
        'display_name' => $name,
    ]);
}

function inboxRelation(Agency $agency, Creator $creator, ?User $owner = null, ?User $inviter = null): AgencyCreatorRelation
{
    return AgencyCreatorRelation::query()->firstOrCreate(
        ['agency_id' => $agency->id, 'creator_id' => $creator->id],
        [
            'relationship_status' => 'roster',
            'is_blacklisted' => false,
            'owner_user_id' => $owner?->id,
            'invited_by_user_id' => $inviter?->id,
        ],
    );
}

/**
 * A Direct thread with $humans messages from the creator (unread for an agency viewer unless read).
 *
 * @return array{thread: RelationshipThread, messages: list<RelationshipMessage>}
 */
function inboxDirect(Agency $agency, Creator $creator, int $humans = 1, ?Carbon $at = null, ?User $owner = null): array
{
    inboxRelation($agency, $creator, $owner);
    $at ??= now();
    $thread = RelationshipThread::factory()->forPair($agency, $creator)->createOne(['last_message_at' => $at]);

    $messages = [];
    for ($i = 0; $i < $humans; $i++) {
        $messages[] = RelationshipMessage::factory()->fromCreator()->createOne([
            'thread_id' => $thread->id,
            'sender_user_id' => $creator->user_id,
            'sender_role' => MessageSenderRole::Creator,
            'body' => "direct {$i}",
        ]);
    }

    return ['thread' => $thread, 'messages' => $messages];
}

/**
 * An assignment thread under (brand, campaign) with $humans creator messages and $system system lines.
 *
 * @return array{thread: MessageThread, assignment: CampaignAssignment, campaign: Campaign, brand: Brand, creator: Creator, messages: list<Message>}
 */
function inboxAssignment(Agency $agency, ?Brand $brand = null, ?Campaign $campaign = null, ?Creator $creator = null, int $humans = 1, int $system = 0, ?Carbon $at = null, ?User $owner = null): array
{
    $brand ??= Brand::factory()->forAgency($agency->id)->createOne();
    $campaign ??= Campaign::factory()->createOne(['agency_id' => $agency->id, 'brand_id' => $brand->id]);
    $creator ??= inboxCreator('Assigned');
    inboxRelation($agency, $creator, $owner);
    $at ??= now();

    $assignment = CampaignAssignment::factory()->status(AssignmentStatus::Accepted)->createOne([
        'agency_id' => $agency->id,
        'campaign_id' => $campaign->id,
        'brand_id' => $campaign->brand_id,
        'creator_id' => $creator->id,
    ]);
    $thread = MessageThread::factory()->forAssignment($assignment)->createOne(['last_message_at' => $at]);

    $messages = [];
    for ($i = 0; $i < $humans; $i++) {
        $messages[] = Message::factory()->fromCreator()->createOne([
            'thread_id' => $thread->id,
            'sender_user_id' => $creator->user_id,
            'body' => "assignment {$i}",
        ]);
    }
    for ($i = 0; $i < $system; $i++) {
        $messages[] = Message::factory()->system('assignment.offer_edited')->createOne(['thread_id' => $thread->id]);
    }

    return ['thread' => $thread, 'assignment' => $assignment, 'campaign' => $campaign, 'brand' => $brand, 'creator' => $creator, 'messages' => $messages];
}

function inboxMarkRead(User $viewer, Message|RelationshipMessage $message): void
{
    $message instanceof Message
        ? MessageReadReceipt::query()->create(['message_id' => $message->id, 'user_id' => $viewer->id, 'read_at' => now()])
        : RelationshipMessageReadReceipt::query()->create(['message_id' => $message->id, 'user_id' => $viewer->id, 'read_at' => now()]);
}

/**
 * A JSON path of an inbox response as a typed collection.
 *
 * @param  TestResponse<Response>  $response
 * @return Collection<int|string, mixed>
 */
function inboxList(TestResponse $response, string $path = 'data'): Collection
{
    /** @var array<int|string, mixed> $value */
    $value = $response->json($path) ?? [];

    return new Collection($value);
}
