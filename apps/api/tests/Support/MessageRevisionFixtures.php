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
use App\Modules\Messaging\Features\MessagesV2EditSearchEnabled;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageThread;
use App\Modules\Messaging\Models\RelationshipMessage;
use App\Modules\Messaging\Models\RelationshipThread;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Pennant\Feature;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| AH-119 Stage B — shared fixtures for the edit, delete and search specs
|--------------------------------------------------------------------------
| Plain functions (the repo's Pest idiom), loaded with require_once. `rev*` prefix.
*/

/**
 * One agency with an admin, a manager and a staff member, one approved rostered creator, and BOTH a
 * relationship thread and a campaign (assignment) thread between them.
 *
 * @return array{agency: Agency, admin: User, manager: User, staff: User, creator: Creator, creatorUser: User, campaign: Campaign, assignment: CampaignAssignment, direct: RelationshipThread, thread: MessageThread}
 */
function revWorld(bool $flag = true, AssignmentStatus $status = AssignmentStatus::Accepted): array
{
    $flag ? Feature::activate(MessagesV2EditSearchEnabled::NAME) : Feature::deactivate(MessagesV2EditSearchEnabled::NAME);

    $agency = Agency::factory()->createOne();
    $creatorUser = User::factory()->createOne();
    $creator = CreatorFactory::new()->approved()->createOne(['user_id' => $creatorUser->id, 'display_name' => 'Ana Creator']);
    AgencyCreatorRelation::factory()->createOne([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => 'roster',
        'is_blacklisted' => false,
    ]);

    $brand = Brand::factory()->forAgency($agency->id)->createOne();
    $campaign = Campaign::factory()->createOne(['agency_id' => $agency->id, 'brand_id' => $brand->id]);
    $assignment = CampaignAssignment::factory()->status($status)->createOne([
        'agency_id' => $agency->id,
        'campaign_id' => $campaign->id,
        'brand_id' => $brand->id,
        'creator_id' => $creator->id,
    ]);

    return [
        'agency' => $agency,
        'admin' => User::factory()->agencyAdmin($agency)->createOne(),
        'manager' => User::factory()->agencyManager($agency)->createOne(),
        'staff' => User::factory()->agencyStaff($agency)->createOne(),
        'creator' => $creator,
        'creatorUser' => $creatorUser,
        'campaign' => $campaign,
        'assignment' => $assignment,
        'direct' => RelationshipThread::factory()->forPair($agency, $creator)->createOne(),
        'thread' => MessageThread::factory()->forAssignment($assignment)->createOne(),
    ];
}

function revDirectMessage(RelationshipThread $thread, User $sender, bool $fromCreator, string $body = 'hello there'): RelationshipMessage
{
    $factory = RelationshipMessage::factory();

    return ($fromCreator ? $factory->fromCreator() : $factory)->createOne([
        'thread_id' => $thread->id,
        'sender_user_id' => $sender->id,
        'sender_role' => $fromCreator ? MessageSenderRole::Creator : MessageSenderRole::AgencyUser,
        'body' => $body,
    ]);
}

function revCampaignMessage(MessageThread $thread, User $sender, bool $fromCreator, string $body = 'hello there'): Message
{
    $factory = Message::factory();

    return ($fromCreator ? $factory->fromCreator() : $factory)->createOne([
        'thread_id' => $thread->id,
        'sender_user_id' => $sender->id,
        'sender_role' => $fromCreator ? MessageSenderRole::Creator : MessageSenderRole::AgencyUser,
        'body' => $body,
    ]);
}

/**
 * The four URL families, keyed by surface. `$message` is a message ulid.
 *
 * @param  array{agency: Agency, admin: User, manager: User, staff: User, creator: Creator, creatorUser: User, campaign: Campaign, assignment: CampaignAssignment, direct: RelationshipThread, thread: MessageThread}  $w
 * @return array<string, string>
 */
function revUrls(array $w, string $message): array
{
    return [
        'agency-campaign' => "/api/v1/agencies/{$w['agency']->ulid}/campaigns/{$w['campaign']->ulid}/assignments/{$w['assignment']->ulid}/messages/{$message}",
        'agency-direct' => "/api/v1/agencies/{$w['agency']->ulid}/creators/{$w['creator']->ulid}/relationship-messages/{$message}",
        'creator-campaign' => "/api/v1/creators/me/assignments/{$w['assignment']->ulid}/messages/{$message}",
        'creator-direct' => "/api/v1/creators/me/relationship-threads/{$w['agency']->ulid}/messages/{$message}",
    ];
}

/**
 * The four surfaces with the user who sends there, the message factory and the resource `type`.
 * Used to run the same assertions on every surface.
 *
 * @param  array{agency: Agency, admin: User, manager: User, staff: User, creator: Creator, creatorUser: User, campaign: Campaign, assignment: CampaignAssignment, direct: RelationshipThread, thread: MessageThread}  $w  a {@see revWorld()}
 * @return array<string, array{url: string, actor: User, message: Message|RelationshipMessage, type: string, table: string}>
 */
function revSurfaces(array $w, string $body = 'original text', ?string $only = null): array
{
    $out = [];
    $make = [
        'agency-campaign' => fn () => revCampaignMessage($w['thread'], $w['admin'], false, $body),
        'agency-direct' => fn () => revDirectMessage($w['direct'], $w['admin'], false, $body),
        'creator-campaign' => fn () => revCampaignMessage($w['thread'], $w['creatorUser'], true, $body),
        'creator-direct' => fn () => revDirectMessage($w['direct'], $w['creatorUser'], true, $body),
    ];
    foreach ($make as $surface => $factory) {
        if ($only !== null && $surface !== $only) {
            continue;
        }
        $message = $factory();
        $out[$surface] = [
            'url' => revUrls($w, $message->ulid)[$surface],
            'actor' => str_starts_with($surface, 'agency') ? $w['admin'] : $w['creatorUser'],
            'message' => $message,
            'type' => str_contains($surface, 'campaign') ? 'message' : 'relationship_message',
            'table' => str_contains($surface, 'campaign') ? 'messages' : 'relationship_messages',
        ];
    }

    return $out;
}

/**
 * The four surfaces as a keyed Pest dataset (names only; each test builds its own world).
 *
 * @return array<string, array{0: string}>
 */
function revSurfaceDataset(): array
{
    return [
        'agency-campaign' => ['agency-campaign'],
        'agency-direct' => ['agency-direct'],
        'creator-campaign' => ['creator-campaign'],
        'creator-direct' => ['creator-direct'],
    ];
}

/**
 * One surface of a world, by name.
 *
 * @param  array{agency: Agency, admin: User, manager: User, staff: User, creator: Creator, creatorUser: User, campaign: Campaign, assignment: CampaignAssignment, direct: RelationshipThread, thread: MessageThread}  $w
 * @return array{url: string, actor: User, message: Message|RelationshipMessage, type: string, table: string}
 */
function revSurface(array $w, string $name, string $body = 'original text'): array
{
    return revSurfaces($w, $body, $name)[$name];
}

/**
 * @return object{id: int, ulid: string, body: string|null, edited_at: string|null, original_body: string|null, deleted_at: string|null, deleted_body: string|null, deleted_by_user_id: int|string|null, attachments: string|null, created_at: string, updated_at: string}
 */
function revRow(string $table, string $ulid): object
{
    /** @var object{id: int, ulid: string, body: string|null, edited_at: string|null, original_body: string|null, deleted_at: string|null, deleted_body: string|null, deleted_by_user_id: int|string|null, attachments: string|null, created_at: string, updated_at: string} $row */
    $row = DB::table($table)->where('ulid', $ulid)->first();

    return $row;
}

/**
 * @param  array{agency: Agency, admin: User, manager: User, staff: User, creator: Creator, creatorUser: User, campaign: Campaign, assignment: CampaignAssignment, direct: RelationshipThread, thread: MessageThread}  $w
 * @return TestResponse<JsonResponse>
 */
function revSearch(array $w, string $q, ?string $cursor = null, ?User $as = null): TestResponse
{
    /** @var TestCase $case */
    $case = test(); // @phpstan-ignore varTag.nativeType
    $url = "/api/v1/agencies/{$w['agency']->ulid}/message-search?q=".rawurlencode($q).($cursor === null ? '' : '&cursor='.rawurlencode($cursor));

    return $case->actingAs($as ?? $w['admin'])->getJson($url);
}
