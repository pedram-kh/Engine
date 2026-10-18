<?php

declare(strict_types=1);

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
use App\Modules\Messaging\Models\RelationshipThread;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Models\Notification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * AH-108 (4a) — opening a thread marks THAT thread's message notifications read.
 *
 * `notifications.read_at` is the single source of truth (Q5), so the write lives
 * in both `markThreadReadForUser` methods (D1: campaign chat + relationship chat,
 * agency side included) and is keyed by (recipient, subject_type, subject_id,
 * type IN message types) — never by the JSON `data` payload.
 *
 * Two things carry the weight and each has a test that fails without it:
 *
 *   - OVER-REACH: the update must touch nothing but the viewer's own, unread,
 *     message-type rows for exactly this thread. A too-wide WHERE would silently
 *     clear unrelated alerts (an `assignment.*` row on the same assignment is the
 *     realistic case); a too-narrow one leaves the badge stuck.
 *   - EARLY RETURN: the SPA only calls `/read` when the thread's unread count is
 *     above zero, and the service returns early when there are no unread
 *     MESSAGES. A notification whose message was already read (opened on the
 *     other surface, or before this shipped) must still be cleared, so the write
 *     happens BEFORE the early return.
 */

/**
 * @return array{agency: Agency, campaign: Campaign, assignment: CampaignAssignment, creator: Creator, creatorUser: User, admin: User, manager: User}
 */
function rmnCampaignSetup(): array
{
    $agency = Agency::factory()->createOne();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();
    $campaign = Campaign::factory()->createOne(['agency_id' => $agency->id, 'brand_id' => $brand->id]);
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $manager = User::factory()->agencyManager($agency)->createOne();

    $creatorUser = User::factory()->createOne();
    $creator = CreatorFactory::new()->createOne(['user_id' => $creatorUser->id]);

    $assignment = rmnAssignment($agency, $campaign, $brand, $creator, $admin);

    return compact('agency', 'campaign', 'assignment', 'creator', 'creatorUser', 'admin', 'manager');
}

function rmnAssignment(Agency $agency, Campaign $campaign, Brand $brand, Creator $creator, User $admin): CampaignAssignment
{
    return CampaignAssignment::factory()->status(AssignmentStatus::Contracted)->createOne([
        'agency_id' => $agency->id,
        'campaign_id' => $campaign->id,
        'brand_id' => $brand->id,
        'creator_id' => $creator->id,
        'invited_by_user_id' => $admin->id,
    ]);
}

function rmnAgencyMsgUrl(Agency $agency, Campaign $campaign, CampaignAssignment $assignment, string $suffix = ''): string
{
    return "/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}/assignments/{$assignment->ulid}/messages{$suffix}";
}

function rmnCreatorMsgUrl(CampaignAssignment $assignment, string $suffix = ''): string
{
    return "/api/v1/creators/me/assignments/{$assignment->ulid}/messages{$suffix}";
}

/**
 * @return array{agency: Agency, creator: Creator, creatorUser: User, admin: User}
 */
function rmnRelSetup(): array
{
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

    return compact('agency', 'creator', 'creatorUser', 'admin');
}

function rmnAgencyRelUrl(Agency $agency, Creator $creator, string $suffix = ''): string
{
    return "/api/v1/agencies/{$agency->ulid}/creators/{$creator->ulid}/relationship-messages{$suffix}";
}

function rmnCreatorRelUrl(Agency $agency, string $suffix = ''): string
{
    return "/api/v1/creators/me/relationship-threads/{$agency->ulid}/messages{$suffix}";
}

function rmnThread(Agency $agency, Creator $creator): RelationshipThread
{
    return RelationshipThread::withoutGlobalScopes()
        ->where('agency_id', $agency->id)->where('creator_id', $creator->id)->firstOrFail();
}

/** A hand-built notification row for a recipient on an arbitrary subject. */
function rmnRow(User $recipient, NotificationType $type, string $subjectType, int $subjectId, ?DateTimeInterface $readAt = null): Notification
{
    return Notification::factory()->ofType($type)->createOne([
        'recipient_user_id' => $recipient->id,
        'subject_type' => $subjectType,
        'subject_id' => $subjectId,
        'read_at' => $readAt,
    ]);
}

function rmnIsRead(Notification $row): bool
{
    return $row->fresh()?->read_at !== null;
}

function rmnMessageRows(User $recipient, NotificationType $type): Collection
{
    return Notification::query()->where('recipient_user_id', $recipient->id)->where('type', $type->value)->get();
}

// ── NotificationType::messageTypes() ───────────────────────────────────────

it('messageTypes() is exactly the four message-received types', function (): void {
    $values = array_map(static fn (NotificationType $t): string => $t->value, NotificationType::messageTypes());
    sort($values);

    expect($values)->toBe([
        'message.received_by_agency',
        'message.received_by_creator',
        'message.relationship_received_by_agency',
        'message.relationship_received_by_creator',
    ]);
});

// ── Campaign chat, creator reads ───────────────────────────────────────────

it('a creator /read marks THIS assignment\'s message notifications read and nothing else', function (): void {
    $s = rmnCampaignSetup();
    $assignmentType = $s['assignment']->getMorphClass();

    $this->actingAs($s['admin'])->postJson(rmnAgencyMsgUrl($s['agency'], $s['campaign'], $s['assignment']), ['body' => 'a1'])->assertCreated();
    $this->actingAs($s['admin'])->postJson(rmnAgencyMsgUrl($s['agency'], $s['campaign'], $s['assignment']), ['body' => 'a2'])->assertCreated();
    $own = rmnMessageRows($s['creatorUser'], NotificationType::MessageReceivedByCreator);
    expect($own)->toHaveCount(2);

    // Rows that must NOT be touched.
    //  - an assignment.* row on the SAME assignment (the realistic over-reach)
    $sameAssignmentOtherType = rmnRow($s['creatorUser'], NotificationType::AssignmentAccepted, $assignmentType, $s['assignment']->id);
    //  - a message row on a DIFFERENT assignment of the same creator
    $brand = Brand::factory()->forAgency($s['agency']->id)->createOne();
    $campaign2 = Campaign::factory()->createOne(['agency_id' => $s['agency']->id, 'brand_id' => $brand->id]);
    $other = rmnAssignment($s['agency'], $campaign2, $brand, $s['creator'], $s['admin']);
    $otherAssignment = rmnRow($s['creatorUser'], NotificationType::MessageReceivedByCreator, $assignmentType, $other->id);
    //  - another recipient's message row on the SAME assignment
    $otherRecipient = rmnRow($s['admin'], NotificationType::MessageReceivedByAgency, $assignmentType, $s['assignment']->id);
    //  - same numeric subject_id, DIFFERENT subject_type (ids collide across tables)
    $collision = rmnRow($s['creatorUser'], NotificationType::MessageReceivedByCreator, $s['campaign']->getMorphClass(), $s['assignment']->id);
    //  - already read: its read_at must be preserved exactly
    $stamp = now()->subYear()->startOfSecond();
    $alreadyRead = rmnRow($s['creatorUser'], NotificationType::MessageReceivedByCreator, $assignmentType, $s['assignment']->id, $stamp);

    $this->actingAs($s['creatorUser'])->postJson(rmnCreatorMsgUrl($s['assignment'], '/read'))
        ->assertOk()->assertJsonPath('meta.marked', 2);

    foreach ($own as $row) {
        expect(rmnIsRead($row))->toBeTrue();
    }
    expect(rmnIsRead($sameAssignmentOtherType))->toBeFalse('an assignment.* row on the same assignment must stay unread')
        ->and(rmnIsRead($otherAssignment))->toBeFalse('another assignment\'s message row must stay unread')
        ->and(rmnIsRead($otherRecipient))->toBeFalse('another recipient\'s row must stay unread')
        ->and(rmnIsRead($collision))->toBeFalse('a row with the same id on a different subject_type must stay unread')
        ->and($alreadyRead->fresh()?->read_at?->equalTo($stamp))->toBeTrue('an already-read row keeps its read_at');
});

it('a second /read is idempotent: read_at of cleared rows does not move', function (): void {
    $s = rmnCampaignSetup();

    $this->actingAs($s['admin'])->postJson(rmnAgencyMsgUrl($s['agency'], $s['campaign'], $s['assignment']), ['body' => 'a1'])->assertCreated();
    $row = rmnMessageRows($s['creatorUser'], NotificationType::MessageReceivedByCreator)->firstOrFail();

    $this->actingAs($s['creatorUser'])->postJson(rmnCreatorMsgUrl($s['assignment'], '/read'))->assertOk();
    $first = $row->fresh()?->read_at;
    expect($first)->not->toBeNull();

    $this->travel(5)->minutes();
    $this->actingAs($s['creatorUser'])->postJson(rmnCreatorMsgUrl($s['assignment'], '/read'))->assertOk();

    expect($row->fresh()?->read_at?->equalTo($first))->toBeTrue();
});

it('a notification whose messages are already read is STILL cleared (write precedes the early return)', function (): void {
    $s = rmnCampaignSetup();

    $this->actingAs($s['admin'])->postJson(rmnAgencyMsgUrl($s['agency'], $s['campaign'], $s['assignment']), ['body' => 'a1'])->assertCreated();
    // Every message is now read (receipts exist) …
    $this->actingAs($s['creatorUser'])->postJson(rmnCreatorMsgUrl($s['assignment'], '/read'))
        ->assertOk()->assertJsonPath('meta.marked', 1);

    // … but a stale unread notification for the thread remains (the pre-AH-108
    // backlog, or one whose message was read on the other surface).
    $stale = rmnRow($s['creatorUser'], NotificationType::MessageReceivedByCreator, $s['assignment']->getMorphClass(), $s['assignment']->id);

    $this->actingAs($s['creatorUser'])->postJson(rmnCreatorMsgUrl($s['assignment'], '/read'))
        ->assertOk()->assertJsonPath('meta.marked', 0);

    expect(rmnIsRead($stale))->toBeTrue();
});

// ── Campaign chat, agency reads ────────────────────────────────────────────

it('an agency member /read clears only THEIR message notifications, not a colleague\'s', function (): void {
    $s = rmnCampaignSetup();

    $this->actingAs($s['creatorUser'])->postJson(rmnCreatorMsgUrl($s['assignment']), ['body' => 'c1'])->assertCreated();
    $this->actingAs($s['creatorUser'])->postJson(rmnCreatorMsgUrl($s['assignment']), ['body' => 'c2'])->assertCreated();

    $adminRows = rmnMessageRows($s['admin'], NotificationType::MessageReceivedByAgency);
    $managerRows = rmnMessageRows($s['manager'], NotificationType::MessageReceivedByAgency);
    expect($adminRows)->toHaveCount(2)->and($managerRows)->toHaveCount(2);

    $this->actingAs($s['admin'])->postJson(rmnAgencyMsgUrl($s['agency'], $s['campaign'], $s['assignment'], '/read'))
        ->assertOk();

    foreach ($adminRows as $row) {
        expect(rmnIsRead($row))->toBeTrue();
    }
    foreach ($managerRows as $row) {
        expect(rmnIsRead($row))->toBeFalse('a colleague\'s notifications are theirs to clear');
    }
});

// ── Relationship chat (both sides) ─────────────────────────────────────────

it('a creator /read on a relationship thread clears that thread\'s notifications only', function (): void {
    $s = rmnRelSetup();

    $this->actingAs($s['admin'])->postJson(rmnAgencyRelUrl($s['agency'], $s['creator']), ['body' => 'hi'])->assertCreated();
    $this->actingAs($s['admin'])->postJson(rmnAgencyRelUrl($s['agency'], $s['creator']), ['body' => 'hi again'])->assertCreated();
    $own = rmnMessageRows($s['creatorUser'], NotificationType::MessageRelationshipReceivedByCreator);
    expect($own)->toHaveCount(2);
    $thread = rmnThread($s['agency'], $s['creator']);

    // A second agency's thread for the same creator.
    $s2 = rmnRelSetup();
    AgencyCreatorRelation::factory()->createOne([
        'agency_id' => $s2['agency']->id,
        'creator_id' => $s['creator']->id,
        'relationship_status' => RelationshipStatus::Roster,
        'is_blacklisted' => false,
    ]);
    $this->actingAs($s2['admin'])->postJson(rmnAgencyRelUrl($s2['agency'], $s['creator']), ['body' => 'other agency'])->assertCreated();
    $otherThread = rmnMessageRows($s['creatorUser'], NotificationType::MessageRelationshipReceivedByCreator)
        ->reject(fn (Notification $n): bool => $own->contains('id', $n->id))
        ->firstOrFail();

    $otherRecipient = rmnRow($s['admin'], NotificationType::MessageRelationshipReceivedByAgency, $thread->getMorphClass(), $thread->id);
    $collision = rmnRow($s['creatorUser'], NotificationType::MessageRelationshipReceivedByCreator, $s['agency']->getMorphClass(), $thread->id);

    $this->actingAs($s['creatorUser'])->postJson(rmnCreatorRelUrl($s['agency'], '/read'))
        ->assertOk()->assertJsonPath('meta.marked', 2);

    foreach ($own as $row) {
        expect(rmnIsRead($row))->toBeTrue();
    }
    expect(rmnIsRead($otherThread))->toBeFalse('another thread\'s row must stay unread')
        ->and(rmnIsRead($otherRecipient))->toBeFalse('another recipient\'s row must stay unread')
        ->and(rmnIsRead($collision))->toBeFalse('same id, different subject_type must stay unread');
});

it('an agency member /read on a relationship thread clears their notifications only', function (): void {
    $s = rmnRelSetup();
    $colleague = User::factory()->agencyManager($s['agency'])->createOne();

    $this->actingAs($s['creatorUser'])->postJson(rmnCreatorRelUrl($s['agency']), ['body' => 'hey'])->assertCreated();
    $adminRows = rmnMessageRows($s['admin'], NotificationType::MessageRelationshipReceivedByAgency);
    $colleagueRows = rmnMessageRows($colleague, NotificationType::MessageRelationshipReceivedByAgency);
    expect($adminRows)->toHaveCount(1)->and($colleagueRows)->toHaveCount(1);

    $this->actingAs($s['admin'])->postJson(rmnAgencyRelUrl($s['agency'], $s['creator'], '/read'))->assertOk();

    expect(rmnIsRead($adminRows->first()))->toBeTrue()
        ->and(rmnIsRead($colleagueRows->first()))->toBeFalse();
});

it('a relationship notification whose messages are already read is STILL cleared', function (): void {
    $s = rmnRelSetup();

    $this->actingAs($s['admin'])->postJson(rmnAgencyRelUrl($s['agency'], $s['creator']), ['body' => 'hi'])->assertCreated();
    $this->actingAs($s['creatorUser'])->postJson(rmnCreatorRelUrl($s['agency'], '/read'))
        ->assertOk()->assertJsonPath('meta.marked', 1);

    $thread = rmnThread($s['agency'], $s['creator']);
    $stale = rmnRow($s['creatorUser'], NotificationType::MessageRelationshipReceivedByCreator, $thread->getMorphClass(), $thread->id);

    $this->actingAs($s['creatorUser'])->postJson(rmnCreatorRelUrl($s['agency'], '/read'))
        ->assertOk()->assertJsonPath('meta.marked', 0);

    expect(rmnIsRead($stale))->toBeTrue();
});
