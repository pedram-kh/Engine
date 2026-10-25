<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\CampaignApplicationStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignApplication;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Campaigns\Services\JobPostedFanOutService;
use App\Modules\Creators\Enums\ApplicationStatus;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Creators\Features\ApplicationNotificationsEnabled;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Services\IncompleteCreatorNudgeEligibility;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageThread;
use App\Modules\Messaging\Models\RelationshipThread;
use App\Modules\Messaging\Services\MessageDigestService;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationPreference;
use App\Modules\TalentPools\Models\TalentPool;
use App\Modules\TalentPools\Models\TalentPoolMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Pennant\Feature;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * AH-104b-del B1 (C4, D3) — the behavioural half of "a creator scheduled for deletion, or
 * erased, is handled on every agency-facing surface". The static half is
 * AgencyCreatorSurfacesTest.
 *
 * Three creators in every test: a NORMAL one (the symmetric bystander, who must keep
 * appearing and keep being told), and the one under test. The rule:
 *   - new doors refuse with a neutral 422 `creator.unavailable` (never "pending deletion");
 *   - discovery and the fan-outs skip them;
 *   - existing rows, threads and assignments are untouched, and existing threads stay
 *     writable but silent;
 *   - cancelling the request makes the creator reappear with no trace.
 */

/** @return array{agency: Agency, admin: User, brand: Brand} */
function cuAgency(): array
{
    $agency = Agency::factory()->createOne();

    return [
        'agency' => $agency,
        'admin' => User::factory()->agencyAdmin($agency)->createOne(),
        'brand' => Brand::factory()->forAgency($agency->id)->createOne(),
    ];
}

/** @param string $state one of 'normal', 'pending', 'claimed' (force-claimed, never asked: AH-104c C2-0), 'erased' */
function cuCreator(string $state = 'normal', array $creator = []): Creator
{
    $user = User::factory()->creator()->createOne($state === 'erased' ? ['name' => '[erased]'] : []);

    if ($state === 'pending') {
        $user->forceFill(['deletion_requested_at' => now(), 'deletion_scheduled_for' => now()->addDays(14)])->save();
    }

    if ($state === 'claimed') {
        $user->forceFill(['erasure_started_at' => now()])->save();
    }

    $factory = Creator::factory()->approved();
    if ($state === 'erased') {
        $factory = $factory->erased();
        $creator += ['display_name' => null, 'avatar_path' => null];
        $user->forceFill(['erased_at' => now()])->save();
    }

    return $factory->createOne($creator + ['user_id' => $user->id]);
}

function cuRoster(Agency $agency, Creator $creator): AgencyCreatorRelation
{
    return AgencyCreatorRelation::factory()->createOne([
        'agency_id' => $agency->id,
        'creator_id' => $creator->id,
        'relationship_status' => RelationshipStatus::Roster,
        'is_blacklisted' => false,
    ]);
}

function cuCancel(Creator $creator): void
{
    User::query()->whereKey($creator->user_id)->update(['deletion_requested_at' => null, 'deletion_scheduled_for' => null]);
}

/**
 * @return list<array<string, mixed>>
 */
function cuRows(mixed $json): array
{
    return is_array($json) ? array_values($json) : [];
}

const CU_UNAVAILABLE = ['pending', 'claimed', 'erased'];

// ── The one definition ──────────────────────────────────────────────────────

it('defines "visible to agencies" once: the scope and the single-row check never disagree', function (): void {
    $normal = cuCreator('normal');
    $pending = cuCreator('pending');
    $claimed = cuCreator('claimed');
    $erased = cuCreator('erased');

    $visible = Creator::query()->visibleToAgencies()->pluck('creators.id')->all();

    expect($visible)->toBe([$normal->id])
        ->and($normal->isUnavailableToAgencies())->toBeFalse()
        ->and($claimed->isUnavailableToAgencies())->toBeTrue()
        ->and($pending->isUnavailableToAgencies())->toBeTrue()
        ->and($erased->isUnavailableToAgencies())->toBeTrue()
        ->and($pending->isErased())->toBeFalse()
        ->and($erased->isErased())->toBeTrue();

    cuCancel($pending);

    expect(Creator::query()->visibleToAgencies()->pluck('creators.id')->all())->toContain($pending->id)
        ->and($pending->fresh()?->isUnavailableToAgencies())->toBeFalse();
});

it('does not read a missing erased_at column as "not erased"', function (): void {
    $erased = cuCreator('erased');

    $slim = Creator::query()->select(['id', 'ulid'])->whereKey($erased->id)->firstOrFail();

    expect($slim->isErased())->toBeTrue();
});

// ── Discovery ───────────────────────────────────────────────────────────────

it('leaves a creator out of discovery and answers their profile 404 (a cancelled request puts them back)', function (string $state): void {
    ['agency' => $agency, 'admin' => $admin] = cuAgency();
    $normal = cuCreator('normal', ['display_name' => 'Nora Normal']);
    $creator = cuCreator($state, ['display_name' => $state === 'erased' ? null : 'Pat Pending']);
    $base = "/api/v1/agencies/{$agency->ulid}/creators/discover";

    $ids = array_column(cuRows($this->actingAs($admin)->getJson($base)->assertOk()->json('data')), 'id');

    expect($ids)->toContain($normal->ulid)->and($ids)->not->toContain($creator->ulid);
    $this->actingAs($admin)->getJson("{$base}/{$creator->ulid}")->assertNotFound();
    $this->actingAs($admin)->getJson("{$base}/{$normal->ulid}")->assertOk();

    if ($state === 'pending') {
        cuCancel($creator);
        $this->actingAs($admin)->getJson("{$base}/{$creator->ulid}")->assertOk();
    }
})->with(CU_UNAVAILABLE);

// ── The new doors ───────────────────────────────────────────────────────────

it('refuses a new connection request with the neutral 422 and writes nothing', function (string $state): void {
    Mail::fake();
    ['agency' => $agency, 'admin' => $admin] = cuAgency();
    $creator = cuCreator($state);

    $response = $this->actingAs($admin)
        ->postJson("/api/v1/agencies/{$agency->ulid}/creators/discover/{$creator->ulid}/connection-request");

    $response->assertStatus(422)->assertJsonPath('errors.0.code', 'creator.unavailable');
    expect($response->getContent())->not->toContain('delet')->and($response->getContent())->not->toContain('pending')
        ->and(AgencyCreatorRelation::withoutGlobalScopes()->where('creator_id', $creator->id)->count())->toBe(0);
    Mail::assertNothingSent();
    Mail::assertNothingQueued();
})->with(CU_UNAVAILABLE);

it('refuses an invite with the neutral 422 and creates no assignment', function (string $state): void {
    ['agency' => $agency, 'admin' => $admin, 'brand' => $brand] = cuAgency();
    $campaign = Campaign::factory()->createOne(['agency_id' => $agency->id, 'brand_id' => $brand->id, 'budget_currency' => 'EUR']);
    $normal = cuCreator('normal');
    $creator = cuCreator($state);
    $url = "/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}/assignments";
    $payload = ['agreed_fee_minor_units' => 500_000, 'agreed_fee_currency' => 'EUR'];

    $this->actingAs($admin)->postJson($url, $payload + ['creator_id' => $normal->ulid])->assertCreated();
    $this->actingAs($admin)->postJson($url, $payload + ['creator_id' => $creator->ulid])
        ->assertStatus(422)->assertJsonPath('errors.0.code', 'creator.unavailable');

    expect(CampaignAssignment::withoutGlobalScopes()->where('creator_id', $creator->id)->count())->toBe(0)
        ->and(CampaignAssignment::withoutGlobalScopes()->where('creator_id', $normal->id)->count())->toBe(1);
})->with(CU_UNAVAILABLE);

it('refuses a talent-pool add with the neutral 422 but lets the agency remove a membership', function (string $state): void {
    ['agency' => $agency, 'admin' => $admin, 'brand' => $brand] = cuAgency();
    $pool = TalentPool::factory()->forAgency($agency->id)->createOne();
    $creator = cuCreator($state);
    cuRoster($agency, $creator);
    $url = "/api/v1/agencies/{$agency->ulid}/talent-pools/{$pool->ulid}/creators";

    $this->actingAs($admin)->postJson($url, ['creator_id' => $creator->ulid])
        ->assertStatus(422)->assertJsonPath('errors.0.code', 'creator.unavailable');
    expect(TalentPoolMembership::query()->where('creator_id', $creator->id)->count())->toBe(0);

    TalentPoolMembership::factory()->createOne(['talent_pool_id' => $pool->id, 'creator_id' => $creator->id]);
    $this->actingAs($admin)->deleteJson("{$url}/{$creator->ulid}")->assertOk();
    expect(TalentPoolMembership::query()->where('creator_id', $creator->id)->count())->toBe(0);
})->with(CU_UNAVAILABLE);

/** @return array{agency: Agency, admin: User, campaign: Campaign, creator: Creator, application: CampaignApplication} */
function cuApplication(string $state): array
{
    $ctx = cuAgency();
    $campaign = Campaign::factory()->listed()->createOne([
        'agency_id' => $ctx['agency']->id, 'brand_id' => $ctx['brand']->id, 'budget_currency' => 'EUR',
    ]);
    $creator = cuCreator($state);
    cuRoster($ctx['agency'], $creator);
    $application = CampaignApplication::factory()->createOne([
        'campaign_id' => $campaign->id, 'agency_id' => $ctx['agency']->id, 'creator_id' => $creator->id,
    ]);

    return $ctx + compact('campaign', 'creator', 'application');
}

it('refuses to ACCEPT an application (a new assignment) but leaves the application as it was', function (string $state): void {
    $s = cuApplication($state);
    $base = "/api/v1/agencies/{$s['agency']->ulid}/campaigns/{$s['campaign']->ulid}/applications/{$s['application']->ulid}";

    $this->actingAs($s['admin'])
        ->postJson("{$base}/accept", ['agreed_fee_minor_units' => 500_000, 'agreed_fee_currency' => 'EUR'])
        ->assertStatus(422)->assertJsonPath('errors.0.code', 'creator.unavailable');

    expect($s['application']->fresh()?->status)->toBe(CampaignApplicationStatus::Pending)
        ->and(CampaignAssignment::withoutGlobalScopes()->where('creator_id', $s['creator']->id)->count())->toBe(0);
})->with(CU_UNAVAILABLE);

it('still lets the agency REJECT an application, silently', function (string $state): void {
    Feature::activate(ApplicationNotificationsEnabled::class);
    Mail::fake();
    $s = cuApplication($state);
    $base = "/api/v1/agencies/{$s['agency']->ulid}/campaigns/{$s['campaign']->ulid}/applications/{$s['application']->ulid}";

    $this->actingAs($s['admin'])->postJson("{$base}/reject")->assertOk();

    expect($s['application']->fresh()?->status)->toBe(CampaignApplicationStatus::Rejected)
        ->and(Notification::query()->where('recipient_user_id', $s['creator']->user_id)->count())->toBe(0);
    Mail::assertNothingQueued();
    Mail::assertNothingSent();
})->with(CU_UNAVAILABLE);

it('tells a normal creator about a rejection (the control for the silence above)', function (): void {
    Feature::activate(ApplicationNotificationsEnabled::class);
    Mail::fake();
    $s = cuApplication('normal');

    $this->actingAs($s['admin'])
        ->postJson("/api/v1/agencies/{$s['agency']->ulid}/campaigns/{$s['campaign']->ulid}/applications/{$s['application']->ulid}/reject")
        ->assertOk();

    expect(Notification::query()->where('recipient_user_id', $s['creator']->user_id)->count())->toBeGreaterThan(0);
});

// ── Messaging ───────────────────────────────────────────────────────────────

it('starts no NEW conversation with the creator, but an existing thread stays writable and silent', function (string $state): void {
    Mail::fake();
    ['agency' => $agency, 'admin' => $admin] = cuAgency();
    $creator = cuCreator($state);
    cuRoster($agency, $creator);
    $url = "/api/v1/agencies/{$agency->ulid}/creators/{$creator->ulid}/relationship-messages";

    $this->actingAs($admin)->postJson($url, ['body' => 'first hello'])
        ->assertStatus(422)->assertJsonPath('errors.0.code', 'creator.unavailable');
    expect(RelationshipThread::withoutGlobalScopes()->where('creator_id', $creator->id)->count())->toBe(0);

    RelationshipThread::factory()->createOne(['agency_id' => $agency->id, 'creator_id' => $creator->id]);
    $this->actingAs($admin)->postJson($url, ['body' => 'a thread already exists'])->assertCreated();

    expect(Notification::query()->where('recipient_user_id', $creator->user_id)->count())->toBe(0);
    Mail::assertNothingQueued();
    Mail::assertNothingSent();
})->with(CU_UNAVAILABLE);

it('tells a normal creator the same message (the control for the silence above)', function (): void {
    ['agency' => $agency, 'admin' => $admin] = cuAgency();
    $creator = cuCreator('normal');
    cuRoster($agency, $creator);
    RelationshipThread::factory()->createOne(['agency_id' => $agency->id, 'creator_id' => $creator->id]);

    $this->actingAs($admin)
        ->postJson("/api/v1/agencies/{$agency->ulid}/creators/{$creator->ulid}/relationship-messages", ['body' => 'hello'])
        ->assertCreated();

    expect(Notification::query()
        ->where('recipient_user_id', $creator->user_id)
        ->where('type', NotificationType::MessageRelationshipReceivedByCreator->value)
        ->count())->toBe(1);
});

it('offers the creator in the new-conversation picker only where a thread already exists', function (string $state): void {
    ['agency' => $agency, 'admin' => $admin] = cuAgency();
    $normal = cuCreator('normal');
    $withThread = cuCreator($state);
    $without = cuCreator($state);
    foreach ([$normal, $withThread, $without] as $creator) {
        cuRoster($agency, $creator);
    }
    RelationshipThread::factory()->createOne(['agency_id' => $agency->id, 'creator_id' => $withThread->id]);

    $ids = array_column(
        cuRows($this->actingAs($admin)->getJson("/api/v1/agencies/{$agency->ulid}/messageable-creators")->assertOk()->json('data')),
        'id',
    );

    expect($ids)->toContain($normal->ulid)
        ->and($ids)->toContain($withThread->ulid)
        ->and($ids)->not->toContain($without->ulid);
})->with(CU_UNAVAILABLE);

// ── Fan-outs ────────────────────────────────────────────────────────────────

it('sends the job-posted fan-out to the normal creator and to nobody scheduled for deletion or erased', function (): void {
    ['agency' => $agency, 'brand' => $brand] = cuAgency();
    $campaign = Campaign::factory()->listed()->createOne(['agency_id' => $agency->id, 'brand_id' => $brand->id, 'ends_at' => null]);
    $normal = cuCreator('normal');
    $pending = cuCreator('pending');
    $erased = cuCreator('erased');
    foreach ([$normal, $pending, $erased] as $creator) {
        cuRoster($agency, $creator);
    }

    $recipients = app(JobPostedFanOutService::class)->recipients($campaign)->pluck('id')->all();

    expect($recipients)->toBe([$normal->id]);

    cuCancel($pending);
    expect(app(JobPostedFanOutService::class)->recipients($campaign)->pluck('id')->all())->toContain($pending->id);
});

it('does not nudge a person who asked to delete their account to finish signing up', function (): void {
    $stalled = fn (string $state): Creator => cuCreator($state, [
        'application_status' => ApplicationStatus::Incomplete,
        'created_at' => now()->subDays(3),
        'incomplete_nudge_sent_at' => null,
    ]);
    $normal = $stalled('normal');
    $pending = $stalled('pending');
    $erased = $stalled('erased');

    $eligibility = app(IncompleteCreatorNudgeEligibility::class);
    $ids = [...$eligibility->verifyVariant()->modelKeys(), ...$eligibility->finishVariant()->modelKeys()];

    expect($ids)->toContain($normal->id)
        ->and($ids)->not->toContain($pending->id)
        ->and($ids)->not->toContain($erased->id);
});

it('sends no message digest to the creator, and the control still gets one', function (string $state): void {
    ['agency' => $agency, 'admin' => $admin, 'brand' => $brand] = cuAgency();
    $recipients = [];
    foreach (['normal', $state] as $which) {
        $creator = cuCreator($which);
        $campaign = Campaign::factory()->createOne(['agency_id' => $agency->id, 'brand_id' => $brand->id]);
        $assignment = CampaignAssignment::factory()->createOne([
            'agency_id' => $agency->id, 'campaign_id' => $campaign->id, 'brand_id' => $brand->id, 'creator_id' => $creator->id,
        ]);
        $thread = MessageThread::factory()->createOne(['assignment_id' => $assignment->id]);
        Message::factory()->createOne(['thread_id' => $thread->id, 'sender_user_id' => $admin->id]);
        NotificationPreference::factory()->createOne([
            'user_id' => $creator->user_id,
            'type' => NotificationType::MessageReceivedByCreator->value,
            'channel' => 'digest',
            'is_enabled' => true,
        ]);
        $recipients[$which === 'normal' ? 'normal' : 'test'] = $creator->user_id;
    }

    $digestRecipients = array_map(
        static fn ($digest): int => $digest->recipient->id,
        app(MessageDigestService::class)->pendingDigests(),
    );

    expect($digestRecipients)->toContain($recipients['normal'])
        ->and($digestRecipients)->not->toContain($recipients['test']);
})->with(CU_UNAVAILABLE);

// ── History is unchanged during the grace ───────────────────────────────────

it('keeps a pending creator on the roster exactly as before, and flags only an erased one', function (): void {
    ['agency' => $agency, 'admin' => $admin] = cuAgency();
    $pending = cuCreator('pending', ['display_name' => 'Pat Pending']);
    $erased = cuCreator('erased');
    cuRoster($agency, $pending);
    cuRoster($agency, $erased);

    $rows = [];
    foreach (cuRows($this->actingAs($admin)->getJson("/api/v1/agencies/{$agency->ulid}/creators")->assertOk()->json('data')) as $row) {
        $rows[(string) data_get($row, 'attributes.creator_id')] = $row;
    }

    expect($rows[$pending->ulid]['attributes']['display_name'])->toBe('Pat Pending')
        ->and($rows[$pending->ulid]['attributes']['is_erased'])->toBeFalse()
        ->and($rows[$erased->ulid]['attributes']['display_name'])->toBeNull()
        ->and($rows[$erased->ulid]['attributes']['is_erased'])->toBeTrue();

    $detail = $this->actingAs($admin)->getJson("/api/v1/agencies/{$agency->ulid}/creators/{$pending->ulid}")->assertOk();
    expect($detail->json('data.attributes.creator.display_name') ?? $detail->json('data.attributes.display_name'))->not->toBeNull();
});

it('leaves an existing assignment and its thread of a pending creator exactly as they were', function (): void {
    ['agency' => $agency, 'admin' => $admin, 'brand' => $brand] = cuAgency();
    $campaign = Campaign::factory()->createOne(['agency_id' => $agency->id, 'brand_id' => $brand->id]);
    $creator = cuCreator('normal', ['display_name' => 'Pat Pending']);
    CampaignAssignment::factory()->createOne([
        'agency_id' => $agency->id, 'campaign_id' => $campaign->id, 'brand_id' => $brand->id, 'creator_id' => $creator->id,
    ]);
    $url = "/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}/assignments";

    $before = $this->actingAs($admin)->getJson($url)->assertOk()->json('data');

    User::query()->whereKey($creator->user_id)->update(['deletion_requested_at' => now(), 'deletion_scheduled_for' => now()->addDays(14)]);
    $during = $this->actingAs($admin)->getJson($url)->assertOk()->json('data');
    cuCancel($creator);
    $after = $this->actingAs($admin)->getJson($url)->assertOk()->json('data');

    expect($during)->toBe($before)->and($after)->toBe($before);
});
