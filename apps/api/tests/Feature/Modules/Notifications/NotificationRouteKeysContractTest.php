<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Events\AssignmentTransitioned;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignApplication;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Campaigns\Services\JobPostedFanOutService;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Creators\Features\ApplicationNotificationsEnabled;
use App\Modules\Creators\Features\JobPostedNotificationsEnabled;
use App\Modules\Creators\Features\MissingCreatorMailsEnabled;
use App\Modules\Creators\Features\PostVerifiedMailEnabled;
use App\Modules\Creators\Models\Contract;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Models\RelationshipThread;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Pennant\Feature;
use Tests\Fixtures\JobsBoard\CreatorJobFixture;
use Tests\Support\NotificationRouteKeys;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * AH-103 (C1.2) — the EMIT-SITE contract: every live notification type's REAL
 * emitter writes the route keys the shared contract says it must
 * (`packages/api-client/contracts/notification-route-keys.json`), as the exact
 * ULID of the thing the tap should open.
 *
 * Why this exists (the AH-102b §9 rule). Before this file, `thread_ulid` and
 * `campaign_ulid` were asserted by NO test that ran a real emitter, and the push
 * tests built their data by hand — one even used a key (`round`) the emitter never
 * writes (it writes `version`). A test that types its own input proves the typed
 * input. So each case below drives the real path (the assignment listener, the
 * message HTTP routes, the application endpoints, the job-posted fan-out) and
 * reads the PERSISTED row's `data`, never a literal.
 *
 * Types whose contract `bag` is empty need no emitter proof; they are listed in
 * {@see rkNoKeyTypes()} so that a type that GAINS a key must also gain a driver
 * (the meta test below), instead of slipping through un-driven.
 */

/** Types the contract says carry no route key (their destination needs no id). */
function rkNoKeyTypes(): array
{
    return [
        'campaign_application.rejected',
        'creator.approved',
        'creator.rejected',
        'agency_creator_relation.admin_connected',
        'agency_creator_relation.disconnected',
    ];
}

/** Types with a driver below — dataset keys, so no app boot at collection time. */
function rkDriverTypes(): array
{
    return [
        'assignment.invited',
        'assignment.revision_requested',
        'assignment.draft_approved',
        'assignment.draft_rejected',
        'assignment.completed_on_approval',
        'assignment.manually_verified',
        'assignment.live_verified',
        'assignment.draft_submitted',
        'assignment.contracted',
        'message.received_by_creator',
        'message.received_by_agency',
        'message.relationship_received_by_creator',
        'message.relationship_received_by_agency',
        'campaign_application.accepted',
        'campaign_application.submitted',
        'campaign.job_posted',
    ];
}

// ── Worlds ───────────────────────────────────────────────────────────────────

/**
 * The assignment-listener world: a real assignment on a real campaign, an agency
 * admin (the inviter, who receives the agency-bound types).
 *
 * @return array{assignment: CampaignAssignment, campaign: Campaign}
 */
function rkListenerWorld(): array
{
    Mail::fake();
    foreach ([
        MissingCreatorMailsEnabled::NAME,
        ApplicationNotificationsEnabled::NAME,
        JobPostedNotificationsEnabled::NAME,
        PostVerifiedMailEnabled::NAME,
    ] as $flag) {
        Feature::activate($flag);
    }

    $fixture = CreatorJobFixture::make();
    $inviter = User::factory()->agencyAdmin($fixture->agency)->createOne();
    $assignment = CampaignAssignment::factory()->status(AssignmentStatus::DraftSubmitted)->createOne([
        'agency_id' => $fixture->agency->id,
        'campaign_id' => $fixture->campaign->id,
        'brand_id' => $fixture->brand->id,
        'creator_id' => $fixture->creator->id,
        'invited_by_user_id' => $inviter->id,
        'contract_id' => Contract::factory()->create()->id,
    ]);

    return ['assignment' => $assignment, 'campaign' => $fixture->campaign];
}

function rkFire(CampaignAssignment $assignment, AuditAction $action): void
{
    event(new AssignmentTransitioned(
        assignment: $assignment,
        from: AssignmentStatus::Producing,
        to: AssignmentStatus::DraftSubmitted,
        action: $action,
        triggeredByUserId: null,
    ));
}

/**
 * The campaign-assignment message world (creator + agency admin + manager).
 *
 * @return array{agency: Agency, campaign: Campaign, assignment: CampaignAssignment, creatorUser: User, admin: User}
 */
function rkMessageWorld(): array
{
    $agency = Agency::factory()->createOne();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();
    $campaign = Campaign::factory()->createOne(['agency_id' => $agency->id, 'brand_id' => $brand->id]);
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    User::factory()->agencyManager($agency)->createOne();

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

/**
 * The relationship-thread world: a rostered, approved creator of an agency.
 *
 * @return array{agency: Agency, creatorUser: User, admin: User, creatorUlid: string}
 */
function rkRelationshipWorld(): array
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

    return ['agency' => $agency, 'creatorUser' => $creatorUser, 'admin' => $admin, 'creatorUlid' => $creator->ulid];
}

function rkThreadUlid(): string
{
    return RelationshipThread::withoutGlobalScopes()->firstOrFail()->ulid;
}

// ── Drivers: each returns the key => value pairs the persisted row must carry ──

/**
 * @return array<string, Closure(): array<string, string>>
 */
function rkDrivers(): array
{
    $listenerCreator = static fn (AuditAction $action): Closure => static function () use ($action): array {
        $w = rkListenerWorld();
        rkFire($w['assignment'], $action);

        return ['assignment_ulid' => $w['assignment']->ulid];
    };

    $listenerAgency = static fn (AuditAction $action): Closure => static function () use ($action): array {
        $w = rkListenerWorld();
        rkFire($w['assignment'], $action);

        return ['campaign_ulid' => $w['campaign']->ulid];
    };

    return [
        'assignment.invited' => $listenerCreator(AuditAction::AssignmentInvited),
        'assignment.revision_requested' => $listenerCreator(AuditAction::AssignmentRevisionRequested),
        'assignment.draft_approved' => $listenerCreator(AuditAction::AssignmentDraftApproved),
        'assignment.draft_rejected' => $listenerCreator(AuditAction::AssignmentDraftRejected),
        'assignment.completed_on_approval' => $listenerCreator(AuditAction::AssignmentCompletedOnApproval),
        'assignment.manually_verified' => $listenerCreator(AuditAction::AssignmentManuallyVerified),
        'assignment.live_verified' => $listenerCreator(AuditAction::AssignmentLiveVerified),
        'assignment.draft_submitted' => $listenerAgency(AuditAction::AssignmentDraftSubmitted),
        'assignment.contracted' => $listenerAgency(AuditAction::AssignmentContracted),

        // The real HTTP routes — the same ones MessageNotificationTest drives.
        'message.received_by_creator' => function (): array {
            $s = rkMessageWorld();
            test()->actingAs($s['admin'])
                ->postJson("/api/v1/agencies/{$s['agency']->ulid}/campaigns/{$s['campaign']->ulid}/assignments/{$s['assignment']->ulid}/messages", ['body' => 'welcome aboard'])
                ->assertCreated();

            return ['assignment_ulid' => $s['assignment']->ulid];
        },
        'message.received_by_agency' => function (): array {
            $s = rkMessageWorld();
            test()->actingAs($s['creatorUser'])
                ->postJson("/api/v1/creators/me/assignments/{$s['assignment']->ulid}/messages", ['body' => 'hello agency'])
                ->assertCreated();

            return ['campaign_ulid' => $s['campaign']->ulid];
        },

        // The real relationship-message routes — RelationshipMessageApiTest's.
        'message.relationship_received_by_creator' => function (): array {
            $s = rkRelationshipWorld();
            test()->actingAs($s['admin'])
                ->postJson("/api/v1/agencies/{$s['agency']->ulid}/creators/{$s['creatorUlid']}/relationship-messages", ['body' => 'ping'])
                ->assertCreated();

            return ['thread_ulid' => rkThreadUlid()];
        },
        'message.relationship_received_by_agency' => function (): array {
            $s = rkRelationshipWorld();
            test()->actingAs($s['creatorUser'])
                ->postJson("/api/v1/creators/me/relationship-threads/{$s['agency']->ulid}/messages", ['body' => 'ping'])
                ->assertCreated();

            return ['thread_ulid' => rkThreadUlid()];
        },

        // The real apply endpoint.
        'campaign_application.submitted' => function (): array {
            Mail::fake();
            Feature::activate(ApplicationNotificationsEnabled::NAME);
            $fixture = CreatorJobFixture::make();
            User::factory()->agencyAdmin($fixture->agency)->createOne();

            test()->actingAs($fixture->user)->postJson($fixture->applyUrl())->assertCreated();

            return ['campaign_ulid' => $fixture->campaign->ulid];
        },

        // The real accept endpoint: the assignment ULID is the one the accept just created.
        'campaign_application.accepted' => function (): array {
            Mail::fake();
            Feature::activate(ApplicationNotificationsEnabled::NAME);
            $fixture = CreatorJobFixture::make();
            $admin = User::factory()->agencyAdmin($fixture->agency)->createOne();
            $application = CampaignApplication::factory()->createOne([
                'campaign_id' => $fixture->campaign->id,
                'agency_id' => $fixture->agency->id,
                'creator_id' => $fixture->creator->id,
            ]);

            test()->actingAs($admin)
                ->postJson(
                    "/api/v1/agencies/{$fixture->agency->ulid}/campaigns/{$fixture->campaign->ulid}/applications/{$application->ulid}/accept",
                    ['agreed_fee_minor_units' => 500_000, 'agreed_fee_currency' => 'EUR'],
                )
                ->assertSuccessful();

            return ['assignment_ulid' => CampaignAssignment::withoutGlobalScopes()->where('campaign_id', $fixture->campaign->id)->firstOrFail()->ulid];
        },

        'campaign.job_posted' => function (): array {
            Mail::fake();
            Feature::activate(JobPostedNotificationsEnabled::NAME);
            $fixture = CreatorJobFixture::make();

            app(JobPostedFanOutService::class)->send($fixture->campaign);

            return ['campaign_ulid' => $fixture->campaign->ulid];
        },
    ];
}

// ── The contract ─────────────────────────────────────────────────────────────

it('every contract type is either driven here or declared key-less (a new key forces a driver)', function (): void {
    $contract = array_keys(NotificationRouteKeys::types());
    $covered = [...rkDriverTypes(), ...rkNoKeyTypes()];

    sort($contract);
    sort($covered);

    $driven = array_keys(rkDrivers());
    $declared = rkDriverTypes();
    sort($driven);
    sort($declared);

    expect($covered)->toBe($contract)
        ->and($driven)->toBe($declared);

    foreach (rkNoKeyTypes() as $type) {
        expect(NotificationRouteKeys::bagKeys($type))->toBe([], "{$type} is declared key-less but the contract gives it keys");
    }
    foreach (rkDriverTypes() as $type) {
        expect(NotificationRouteKeys::bagKeys($type))->not->toBe([], "{$type} has a driver but the contract needs no key");
    }
});

it('the REAL emitter writes the contract keys, with the exact ULID of the thing to open', function (string $type): void {
    $expected = rkDrivers()[$type]();

    $rows = Notification::query()->where('type', $type)->get();
    expect($rows->count())->toBeGreaterThan(0, "{$type}: the real emit path wrote no row");

    foreach ($rows as $row) {
        $data = $row->data ?? [];

        foreach (NotificationRouteKeys::bagKeys($type) as $key) {
            expect($data[$key] ?? null)->toBe($expected[$key] ?? '(driver gave no expectation)', "{$type}: data.{$key}");
            expect(preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', (string) ($data[$key] ?? '')))->toBe(1, "{$type}: data.{$key} is not ULID-shaped");
        }
    }
})->with(rkDriverTypes());

it('the creator leg of a campaign message keeps its bag exactly as before (no campaign_ulid leaks into creator rows)', function (): void {
    $s = rkMessageWorld();
    test()->actingAs($s['admin'])
        ->postJson("/api/v1/agencies/{$s['agency']->ulid}/campaigns/{$s['campaign']->ulid}/assignments/{$s['assignment']->ulid}/messages", ['body' => 'welcome aboard'])
        ->assertCreated();

    $row = Notification::query()->where('type', NotificationType::MessageReceivedByCreator->value)->firstOrFail();
    $keys = array_keys($row->data ?? []);
    sort($keys);

    expect($keys)->toBe(['assignment_ulid', 'campaign_name', 'sender_name']);
});
