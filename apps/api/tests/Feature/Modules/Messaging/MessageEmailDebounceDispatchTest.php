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
use App\Modules\Creators\Features\MissingCreatorMailsEnabled;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Mail\NewMessageMail;
use App\Modules\Messaging\Models\MessageEmailDebounce;
use App\Modules\Messaging\Models\MessageThread;
use App\Modules\Messaging\Models\RelationshipThread;
use App\Modules\Messaging\Services\DebouncedMessageMailer;
use App\Modules\Messaging\Services\RelationshipMessageNotifications;
use App\Modules\Messaging\Services\SendMessageNotifications;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Laravel\Pennant\Feature;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * AH-083 (⑧) — S8: the §5.34 debounce disjoint set AGAIN, this time through
 * the REAL dispatch paths (an actual `POST .../messages` HTTP call all the
 * way through {@see SendMessageNotifications}
 * / {@see RelationshipMessageNotifications}
 * into {@see DebouncedMessageMailer}), for
 * BOTH thread models — not the service in isolation
 * ({@see DebouncedMessageMailerTest}, S6). Every case below is duplicated for
 * the campaign-assignment path and the 1:1 relationship path, because C6's
 * whole point is that the two dispatch paths must never drift, and the only
 * way to prove that is to run the same set against both and watch them agree.
 *
 * In-app rows are asserted UNTOUCHED throughout — the debounce is a MAIL-only
 * gate; NotificationService writes its row on every send regardless of the
 * mail decision (D1's scope line).
 *
 * @return array{agency: Agency, campaign: Campaign, assignment: CampaignAssignment, creatorUser: User, admin: User}
 */
function dispatchCampaignSetup(): array
{
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

    return compact('agency', 'campaign', 'assignment', 'creatorUser', 'admin');
}

function dispatchAgencySendUrl(Agency $agency, Campaign $campaign, CampaignAssignment $assignment): string
{
    return "/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}/assignments/{$assignment->ulid}/messages";
}

/**
 * @return array{agency: Agency, creator: Creator, creatorUser: User, admin: User}
 */
function dispatchRelationshipSetup(): array
{
    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $creatorUser = User::factory()->createOne();
    $creator = CreatorFactory::new()->approved()->createOne(['user_id' => $creatorUser->id]);

    AgencyCreatorRelation::factory()->createOne([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::Roster,
    ]);

    return compact('agency', 'creator', 'creatorUser', 'admin');
}

function dispatchAgencyRelUrl(Agency $agency, Creator $creator): string
{
    return "/api/v1/agencies/{$agency->ulid}/creators/{$creator->ulid}/relationship-messages";
}

// ── first-unread emails ──────────────────────────────────────────────────────

it('CAMPAIGN path: the first agency→creator message emails immediately, in-app row still written', function (): void {
    Mail::fake();
    Feature::activate(MissingCreatorMailsEnabled::NAME);
    $s = dispatchCampaignSetup();

    $this->actingAs($s['admin'])
        ->postJson(dispatchAgencySendUrl($s['agency'], $s['campaign'], $s['assignment']), ['body' => 'welcome aboard'])
        ->assertCreated();

    Mail::assertQueued(NewMessageMail::class, fn (NewMessageMail $mail): bool => $mail->context === 'campaign');
    expect(Notification::query()
        ->where('recipient_user_id', $s['creatorUser']->id)
        ->where('type', NotificationType::MessageReceivedByCreator->value)
        ->count())->toBe(1);
});

it('RELATIONSHIP path: the first agency→creator message emails immediately, in-app row still written', function (): void {
    Mail::fake();
    Feature::activate(MissingCreatorMailsEnabled::NAME);
    $s = dispatchRelationshipSetup();

    $this->actingAs($s['admin'])
        ->postJson(dispatchAgencyRelUrl($s['agency'], $s['creator']), ['body' => 'ping'])
        ->assertCreated();

    Mail::assertQueued(NewMessageMail::class, fn (NewMessageMail $mail): bool => $mail->context === 'relationship');
    expect(Notification::query()
        ->where('recipient_user_id', $s['creatorUser']->id)
        ->where('type', 'message.relationship_received_by_creator')
        ->count())->toBe(1);
});

// ── within-30-silent ─────────────────────────────────────────────────────────

it('CAMPAIGN path: a second message 15 minutes later does NOT re-email, but DOES write a second in-app row', function (): void {
    Mail::fake();
    Feature::activate(MissingCreatorMailsEnabled::NAME);
    $s = dispatchCampaignSetup();
    $url = dispatchAgencySendUrl($s['agency'], $s['campaign'], $s['assignment']);

    $this->actingAs($s['admin'])->postJson($url, ['body' => 'first'])->assertCreated();
    Carbon::setTestNow(now()->addMinutes(15));
    $this->actingAs($s['admin'])->postJson($url, ['body' => 'second'])->assertCreated();
    Carbon::setTestNow();

    Mail::assertQueued(NewMessageMail::class, 1);
    expect(Notification::query()
        ->where('recipient_user_id', $s['creatorUser']->id)
        ->where('type', NotificationType::MessageReceivedByCreator->value)
        ->count())->toBe(2);
});

it('RELATIONSHIP path: a second message 15 minutes later does NOT re-email, but DOES write a second in-app row', function (): void {
    Mail::fake();
    Feature::activate(MissingCreatorMailsEnabled::NAME);
    $s = dispatchRelationshipSetup();
    $url = dispatchAgencyRelUrl($s['agency'], $s['creator']);

    $this->actingAs($s['admin'])->postJson($url, ['body' => 'first'])->assertCreated();
    Carbon::setTestNow(now()->addMinutes(15));
    $this->actingAs($s['admin'])->postJson($url, ['body' => 'second'])->assertCreated();
    Carbon::setTestNow();

    Mail::assertQueued(NewMessageMail::class, 1);
    expect(Notification::query()
        ->where('recipient_user_id', $s['creatorUser']->id)
        ->where('type', 'message.relationship_received_by_creator')
        ->count())->toBe(2);
});

// ── after-30-re-emails ───────────────────────────────────────────────────────

it('CAMPAIGN path: a message 31 minutes later re-arms and emails again', function (): void {
    Mail::fake();
    Feature::activate(MissingCreatorMailsEnabled::NAME);
    $s = dispatchCampaignSetup();
    $url = dispatchAgencySendUrl($s['agency'], $s['campaign'], $s['assignment']);

    $this->actingAs($s['admin'])->postJson($url, ['body' => 'first'])->assertCreated();
    Carbon::setTestNow(now()->addMinutes(31));
    $this->actingAs($s['admin'])->postJson($url, ['body' => 'second'])->assertCreated();
    Carbon::setTestNow();

    Mail::assertQueued(NewMessageMail::class, 2);
    expect(MessageEmailDebounce::query()->where('thread_type', MessageThread::class)->count())->toBe(1);
});

it('RELATIONSHIP path: a message 31 minutes later re-arms and emails again', function (): void {
    Mail::fake();
    Feature::activate(MissingCreatorMailsEnabled::NAME);
    $s = dispatchRelationshipSetup();
    $url = dispatchAgencyRelUrl($s['agency'], $s['creator']);

    $this->actingAs($s['admin'])->postJson($url, ['body' => 'first'])->assertCreated();
    Carbon::setTestNow(now()->addMinutes(31));
    $this->actingAs($s['admin'])->postJson($url, ['body' => 'second'])->assertCreated();
    Carbon::setTestNow();

    Mail::assertQueued(NewMessageMail::class, 2);
    expect(MessageEmailDebounce::query()->where('thread_type', RelationshipThread::class)->count())->toBe(1);
});

// ── per-recipient independence ───────────────────────────────────────────────

it('CAMPAIGN path: two different assignments (two different creator recipients) get fully independent windows', function (): void {
    // Same agency/campaign, two different assignments/creators — deliberately
    // NOT two different agencies: crossing agencies within one test method
    // trips an unrelated tenant-scope caching artifact in the test harness
    // (seen and worked around already in InviteEmailNotificationTest), which
    // has nothing to do with the debounce logic this case exists to pin.
    Mail::fake();
    Feature::activate(MissingCreatorMailsEnabled::NAME);
    $s1 = dispatchCampaignSetup();

    $creatorTwoUser = User::factory()->createOne();
    $creatorTwo = CreatorFactory::new()->createOne(['user_id' => $creatorTwoUser->id]);
    $assignmentTwo = CampaignAssignment::factory()->status(AssignmentStatus::Contracted)->createOne([
        'agency_id' => $s1['agency']->id,
        'campaign_id' => $s1['campaign']->id,
        'brand_id' => $s1['assignment']->brand_id,
        'creator_id' => $creatorTwo->id,
        'invited_by_user_id' => $s1['admin']->id,
    ]);

    $this->actingAs($s1['admin'])
        ->postJson(dispatchAgencySendUrl($s1['agency'], $s1['campaign'], $s1['assignment']), ['body' => 'to creator one'])
        ->assertCreated();
    Carbon::setTestNow(now()->addMinutes(15));
    // Creator two's FIRST message, inside creator one's window — must still send.
    $this->actingAs($s1['admin'])
        ->postJson(dispatchAgencySendUrl($s1['agency'], $s1['campaign'], $assignmentTwo), ['body' => 'to creator two'])
        ->assertCreated();
    Carbon::setTestNow();

    Mail::assertQueued(NewMessageMail::class, 2);
    expect(MessageEmailDebounce::query()->where('thread_type', MessageThread::class)->count())->toBe(2);
});

it('RELATIONSHIP path: two different agency-creator pairs get fully independent windows', function (): void {
    Mail::fake();
    Feature::activate(MissingCreatorMailsEnabled::NAME);
    $s1 = dispatchRelationshipSetup();
    $s2 = dispatchRelationshipSetup();

    $this->actingAs($s1['admin'])->postJson(dispatchAgencyRelUrl($s1['agency'], $s1['creator']), ['body' => 'one'])->assertCreated();
    Carbon::setTestNow(now()->addMinutes(15));
    $this->actingAs($s2['admin'])->postJson(dispatchAgencyRelUrl($s2['agency'], $s2['creator']), ['body' => 'two'])->assertCreated();
    Carbon::setTestNow();

    Mail::assertQueued(NewMessageMail::class, 2);
    expect(MessageEmailDebounce::query()->where('thread_type', RelationshipThread::class)->count())->toBe(2);
});

// ── flag-OFF total mail silence, in-app untouched ────────────────────────────

it('CAMPAIGN path: flag OFF — total mail silence, but the in-app row still writes (D1 scope)', function (): void {
    Mail::fake();
    expect(Feature::active(MissingCreatorMailsEnabled::NAME))->toBeFalse();
    $s = dispatchCampaignSetup();

    $this->actingAs($s['admin'])
        ->postJson(dispatchAgencySendUrl($s['agency'], $s['campaign'], $s['assignment']), ['body' => 'welcome aboard'])
        ->assertCreated();

    Mail::assertNothingQueued();
    expect(Notification::query()
        ->where('recipient_user_id', $s['creatorUser']->id)
        ->where('type', NotificationType::MessageReceivedByCreator->value)
        ->count())->toBe(1);
    expect(MessageEmailDebounce::query()->count())->toBe(0);
});

it('RELATIONSHIP path: flag OFF — total mail silence, but the in-app row still writes (D1 scope)', function (): void {
    Mail::fake();
    expect(Feature::active(MissingCreatorMailsEnabled::NAME))->toBeFalse();
    $s = dispatchRelationshipSetup();

    $this->actingAs($s['admin'])
        ->postJson(dispatchAgencyRelUrl($s['agency'], $s['creator']), ['body' => 'ping'])
        ->assertCreated();

    Mail::assertNothingQueued();
    expect(Notification::query()
        ->where('recipient_user_id', $s['creatorUser']->id)
        ->where('type', 'message.relationship_received_by_creator')
        ->count())->toBe(1);
    expect(MessageEmailDebounce::query()->count())->toBe(0);
});

// ── creator→agency direction never touches the mailer (D4 by placement) ─────

it('a creator send on EITHER path never queues NewMessageMail, regardless of the flag', function (): void {
    Mail::fake();
    Feature::activate(MissingCreatorMailsEnabled::NAME);

    $campaign = dispatchCampaignSetup();
    $this->actingAs($campaign['creatorUser'])
        ->postJson("/api/v1/creators/me/assignments/{$campaign['assignment']->ulid}/messages", ['body' => 'hello agency'])
        ->assertCreated();

    $relationship = dispatchRelationshipSetup();
    $this->actingAs($relationship['creatorUser'])
        ->postJson("/api/v1/creators/me/relationship-threads/{$relationship['agency']->ulid}/messages", ['body' => 'hello agency'])
        ->assertCreated();

    Mail::assertNothingQueued();
    expect(MessageEmailDebounce::query()->count())->toBe(0);
});
