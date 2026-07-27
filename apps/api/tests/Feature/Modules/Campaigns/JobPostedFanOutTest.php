<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Agencies\Models\BrandCreatorBlacklist;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\CampaignStatus;
use App\Modules\Campaigns\Jobs\SendJobPostedNotificationsJob;
use App\Modules\Campaigns\Mail\JobPostedMail;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignJobNotification;
use App\Modules\Campaigns\Services\JobPostedFanOutService;
use App\Modules\Campaigns\Services\JobsBoardVisibility;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Enums\ApplicationStatus;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Creators\Features\JobPostedNotificationsEnabled;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Jobs Board chunk 3 (AH-056) — the job-posted fan-out (D6/D7/D8).
 *
 * The four things that matter, in order of how much damage they prevent:
 *
 * 1. **Flag OFF is a no-op.** Nothing queued, nothing stamped. This is the kill
 *    switch that protects T+3, and it gets a break-revert.
 * 2. **The recipient set agrees with the board.** The fan-out asks "which
 *    creators may see this job"; {@see JobsBoardVisibility} asks "which jobs may
 *    this creator see". They are one predicate read from two directions, and a
 *    test walks every recipient back through the board to prove it.
 * 3. **Once-only.** A re-list notifies nobody, because the stamp is already
 *    there.
 * 4. **The cap stamps only the capped set.** A run at the cap must not stamp
 *    the tail it did not notify, or the remainder becomes permanently silent.
 */

/**
 * A listed campaign plus `$rosterSize` approved, rostered creators.
 *
 * @return array{campaign: Campaign, agency: Agency, brand: Brand, creators: list<Creator>}
 */
function fanOutFixture(int $rosterSize = 3, array $campaignOverrides = []): array
{
    $agency = Agency::factory()->createOne();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();

    $campaign = Campaign::factory()->listed()->createOne(array_merge([
        'agency_id' => $agency->id,
        'brand_id' => $brand->id,
        'ends_at' => null,
    ], $campaignOverrides));

    $creators = [];

    foreach (range(1, $rosterSize) as $index) {
        $user = User::factory()->createOne();
        $creator = CreatorFactory::new()->approved()->createOne(['user_id' => $user->id]);

        AgencyCreatorRelation::factory()->createOne([
            'agency_id' => $agency->id,
            'creator_id' => $creator->id,
            'relationship_status' => RelationshipStatus::Roster,
            'is_blacklisted' => false,
            // Deterministic roster age so "oldest-roster-first" is assertable.
            'created_at' => now()->subDays(100 - $index),
        ]);

        $creators[] = $creator;
    }

    return ['campaign' => $campaign, 'agency' => $agency, 'brand' => $brand, 'creators' => $creators];
}

function fanOut(): JobPostedFanOutService
{
    return app(JobPostedFanOutService::class);
}

function enableFanOut(): void
{
    Feature::activate(JobPostedNotificationsEnabled::NAME);
}

function stampCount(Campaign $campaign): int
{
    return CampaignJobNotification::query()->where('campaign_id', $campaign->id)->count();
}

/**
 * The creator's login email — the address the fan-out must reach. Resolved
 * through a fresh query rather than the nullable relation accessor so the
 * assertions below compare two real strings.
 */
function fanOutEmail(Creator $creator): string
{
    return User::query()->findOrFail($creator->user_id)->email;
}

/** Reload a creator, failing loudly instead of handing a null to the predicate. */
function fanOutFresh(Creator $creator): Creator
{
    return Creator::query()->findOrFail($creator->id);
}

/** Reload a campaign, failing loudly instead of handing a null to the service. */
function fanOutReload(Campaign $campaign): Campaign
{
    return Campaign::query()->findOrFail($campaign->id);
}

// ── The flag gate (D6) ──────────────────────────────────────────────────────

it('is a complete no-op while the flag is OFF', function (): void {
    Mail::fake();
    ['campaign' => $campaign] = fanOutFixture();

    // The default state. Not activated anywhere in this test.
    expect(Feature::active(JobPostedNotificationsEnabled::NAME))->toBeFalse();

    $report = fanOut()->send($campaign);

    expect($report->enabled)->toBeFalse()
        ->and($report->notified)->toBe(0)
        // Reported honestly, so an operator reading the log knows what a flip
        // would send.
        ->and($report->remaining)->toBe(3)
        ->and(stampCount($campaign))->toBe(0)
        ->and(Notification::query()->count())->toBe(0);

    Mail::assertNothingQueued();
});

it('sends once the flag is ON', function (): void {
    Mail::fake();
    ['campaign' => $campaign] = fanOutFixture();
    enableFanOut();

    $report = fanOut()->send($campaign);

    expect($report->enabled)->toBeTrue()
        ->and($report->notified)->toBe(3)
        ->and($report->remaining)->toBe(0);
});

// ── §5.2 — one assertion per emission, not one for both ─────────────────────

it('EMISSION 1 — writes an in-app notification row per recipient', function (): void {
    Mail::fake();
    ['campaign' => $campaign, 'agency' => $agency, 'creators' => $creators] = fanOutFixture(2);
    enableFanOut();

    fanOut()->send($campaign);

    $rows = Notification::query()->where('type', 'campaign.job_posted')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('recipient_user_id')->sort()->values()->all())
        ->toBe(collect($creators)->pluck('user_id')->sort()->values()->all());

    $row = $rows->first();

    expect($row?->data['campaign_name'] ?? null)->toBe($campaign->name)
        ->and($row?->data['agency_name'] ?? null)->toBe($agency->name)
        // No actor: attributing a roster-wide alert to the individual staff
        // member who flipped the toggle would put their name in front of
        // everyone.
        ->and($row?->actor_user_id)->toBeNull()
        ->and($row?->subject_id)->toBe($campaign->id);
});

it('EMISSION 2 — queues one localized mail per recipient', function (): void {
    Mail::fake();
    ['campaign' => $campaign, 'creators' => $creators] = fanOutFixture(2);
    enableFanOut();

    fanOut()->send($campaign);

    Mail::assertQueuedCount(2);

    foreach ($creators as $creator) {
        Mail::assertQueued(
            JobPostedMail::class,
            fn (JobPostedMail $mail): bool => $mail->hasTo(fanOutEmail($creator))
                && $mail->campaign->is($campaign),
        );
    }
});

it('queues the mail in the recipient own language, not the senders', function (): void {
    Mail::fake();
    ['campaign' => $campaign, 'creators' => $creators] = fanOutFixture(1);
    $creators[0]->user?->forceFill(['preferred_language' => 'fr'])->save();
    enableFanOut();

    fanOut()->send($campaign);

    Mail::assertQueued(JobPostedMail::class, fn (JobPostedMail $mail): bool => $mail->locale === 'fr');
});

it('EMISSION 3 — stamps each recipient once', function (): void {
    Mail::fake();
    ['campaign' => $campaign, 'creators' => $creators] = fanOutFixture(2);
    enableFanOut();

    fanOut()->send($campaign);

    expect(stampCount($campaign))->toBe(2);

    foreach ($creators as $creator) {
        expect(CampaignJobNotification::query()
            ->where('campaign_id', $campaign->id)
            ->where('creator_id', $creator->id)
            ->exists())->toBeTrue();
    }
});

// ── Once-only: a re-list never re-notifies (D6/D7) ──────────────────────────

it('sends nothing on a second run — the stamp is the whole mechanism', function (): void {
    Mail::fake();
    ['campaign' => $campaign] = fanOutFixture(3);
    enableFanOut();

    expect(fanOut()->send($campaign)->notified)->toBe(3);

    Mail::fake(); // reset the recorder so the second run is measured alone.
    $second = fanOut()->send($campaign);

    expect($second->notified)->toBe(0)
        ->and($second->remaining)->toBe(0)
        ->and(stampCount($campaign))->toBe(3);

    Mail::assertNothingQueued();
});

it('sends nothing when a campaign is delisted and RE-LISTED', function (): void {
    Mail::fake();
    ['campaign' => $campaign] = fanOutFixture(3);
    enableFanOut();

    fanOut()->send($campaign);

    $campaign->forceFill(['listed_on_jobs_board' => false])->save();
    $campaign->forceFill(['listed_on_jobs_board' => true, 'listed_at' => now()])->save();

    Mail::fake();
    expect(fanOut()->send(fanOutReload($campaign))->notified)->toBe(0);
    Mail::assertNothingQueued();
});

it('notifies only the creators a LATER run has not already reached', function (): void {
    Mail::fake();
    ['campaign' => $campaign, 'agency' => $agency] = fanOutFixture(2);
    enableFanOut();

    fanOut()->send($campaign);

    // A creator joins the roster after the first fan-out.
    $latecomer = CreatorFactory::new()->approved()->createOne(['user_id' => User::factory()->createOne()->id]);
    AgencyCreatorRelation::factory()->createOne([
        'agency_id' => $agency->id,
        'creator_id' => $latecomer->id,
        'relationship_status' => RelationshipStatus::Roster,
        'is_blacklisted' => false,
    ]);

    Mail::fake();
    $report = fanOut()->send($campaign);

    expect($report->notified)->toBe(1)
        ->and(stampCount($campaign))->toBe(3);

    Mail::assertQueuedCount(1);
    Mail::assertQueued(JobPostedMail::class, fn (JobPostedMail $mail): bool => $mail->hasTo(fanOutEmail($latecomer)));
});

// ── The cap (D6) ────────────────────────────────────────────────────────────

it('caps a run and stamps ONLY the capped set, oldest roster first', function (): void {
    Mail::fake();
    ['campaign' => $campaign, 'creators' => $creators] = fanOutFixture(5);
    enableFanOut();

    $report = fanOut()->send($campaign, limit: 2);

    expect($report->notified)->toBe(2)
        // The remainder is the whole point of reporting it: three creators are
        // still waiting, and a run at the cap must say so.
        ->and($report->remaining)->toBe(3)
        // ⚠ The tail must NOT be stamped. A stamped-but-unnotified creator is
        // permanently silent for this job — the stamp is what makes re-runs
        // skip people.
        ->and(stampCount($campaign))->toBe(2);

    Mail::assertQueuedCount(2);

    // Oldest roster relation first (the fixture ages them ascending).
    foreach (array_slice($creators, 0, 2) as $reached) {
        Mail::assertQueued(JobPostedMail::class, fn (JobPostedMail $mail): bool => $mail->hasTo(fanOutEmail($reached)));
    }
    foreach (array_slice($creators, 2) as $skipped) {
        Mail::assertNotQueued(JobPostedMail::class, fn (JobPostedMail $mail): bool => $mail->hasTo(fanOutEmail($skipped)));
    }
});

it('drains the remainder over successive capped runs', function (): void {
    Mail::fake();
    ['campaign' => $campaign] = fanOutFixture(5);
    enableFanOut();

    expect(fanOut()->send($campaign, limit: 2)->remaining)->toBe(3);
    expect(fanOut()->send($campaign, limit: 2)->remaining)->toBe(1);

    $final = fanOut()->send($campaign, limit: 2);

    expect($final->notified)->toBe(1)
        ->and($final->remaining)->toBe(0)
        ->and($final->hasRemainder())->toBeFalse()
        ->and(stampCount($campaign))->toBe(5);
});

// ── Dry run mutates nothing (D6) ────────────────────────────────────────────

it('previews without queueing, notifying or stamping — and ignores the flag', function (): void {
    Mail::fake();
    ['campaign' => $campaign] = fanOutFixture(5);

    // Flag deliberately OFF: the preview is what an operator reads BEFORE
    // flipping it, so it must answer honestly rather than reporting zero.
    $report = fanOut()->preview($campaign, limit: 2);

    expect($report->notified)->toBe(2)
        ->and($report->remaining)->toBe(3)
        ->and(stampCount($campaign))->toBe(0)
        ->and(Notification::query()->count())->toBe(0);

    Mail::assertNothingQueued();
});

it('previews the same numbers a send at the same limit produces', function (): void {
    Mail::fake();
    ['campaign' => $campaign] = fanOutFixture(5);

    $preview = fanOut()->preview($campaign, limit: 3);

    enableFanOut();
    $sent = fanOut()->send($campaign, limit: 3);

    expect($sent->notified)->toBe($preview->notified)
        ->and($sent->remaining)->toBe($preview->remaining);
});

// ── The recipient set — the seven cases, from the other direction ───────────

it('LEG 2 — skips a creator whose onboarding is not approved', function (ApplicationStatus $status): void {
    Mail::fake();
    ['campaign' => $campaign, 'creators' => $creators] = fanOutFixture(2);
    $creators[0]->forceFill(['application_status' => $status])->save();
    enableFanOut();

    expect(fanOut()->send($campaign)->notified)->toBe(1)
        ->and(stampCount($campaign))->toBe(1);
})->with([ApplicationStatus::Incomplete, ApplicationStatus::Pending, ApplicationStatus::Rejected]);

it('LEG 3 — skips a creator whose relation is not a clean roster', function (array $mutation): void {
    Mail::fake();
    ['campaign' => $campaign, 'creators' => $creators] = fanOutFixture(2);

    AgencyCreatorRelation::query()
        ->where('creator_id', $creators[0]->id)
        ->update($mutation);

    enableFanOut();

    expect(fanOut()->send($campaign)->notified)->toBe(1);
})->with([
    'ended' => [['relationship_status' => RelationshipStatus::Ended->value]],
    'pending request' => [['relationship_status' => RelationshipStatus::PendingRequest->value]],
    'declined' => [['relationship_status' => RelationshipStatus::Declined->value]],
    'hard blacklist' => [['is_blacklisted' => true, 'blacklist_type' => 'hard']],
    'soft blacklist' => [['is_blacklisted' => true, 'blacklist_type' => 'soft']],
]);

it('LEG 6 — skips a creator the campaign BRAND has hard-blacklisted', function (): void {
    Mail::fake();
    ['campaign' => $campaign, 'brand' => $brand, 'creators' => $creators] = fanOutFixture(2);

    BrandCreatorBlacklist::factory()->createOne([
        'brand_id' => $brand->id,
        'creator_id' => $creators[0]->id,
    ]);

    enableFanOut();

    // The board would hide this job from them and the invite gate would
    // hard-block the resulting application, so emailing them an invitation to
    // apply would be soliciting a dead end (C5).
    expect(fanOut()->send($campaign)->notified)->toBe(1);

    Mail::assertNotQueued(JobPostedMail::class, fn (JobPostedMail $mail): bool => $mail->hasTo(fanOutEmail($creators[0])));
});

it('LEG 6b — still reaches a creator the brand has only SOFT-blacklisted', function (): void {
    Mail::fake();
    ['campaign' => $campaign, 'brand' => $brand, 'creators' => $creators] = fanOutFixture(2);

    BrandCreatorBlacklist::factory()->soft()->createOne([
        'brand_id' => $brand->id,
        'creator_id' => $creators[0]->id,
    ]);

    enableFanOut();

    expect(fanOut()->send($campaign)->notified)->toBe(2);
});

it('LEGS 4 + 5 — sends nothing at all for a campaign that is not visibly listed', function (Closure $mutate): void {
    Mail::fake();
    ['campaign' => $campaign] = fanOutFixture(3);
    $mutate($campaign);
    enableFanOut();

    // Re-checked against the DATABASE inside the service, so a queued job that
    // runs after the campaign stopped qualifying sends nothing.
    $report = fanOut()->send($campaign);

    expect($report->notified)->toBe(0)
        ->and(stampCount($campaign))->toBe(0);

    Mail::assertNothingQueued();
})->with([
    'delisted' => [fn (Campaign $c) => $c->forceFill(['listed_on_jobs_board' => false])->save()],
    'completed' => [fn (Campaign $c) => $c->forceFill(['status' => CampaignStatus::Completed])->save()],
    'cancelled' => [fn (Campaign $c) => $c->forceFill(['status' => CampaignStatus::Cancelled])->save()],
    'expired' => [fn (Campaign $c) => $c->forceFill(['ends_at' => now('UTC')->subDay()])->save()],
]);

it('never reaches a creator rostered with a DIFFERENT agency', function (): void {
    Mail::fake();
    ['campaign' => $campaign] = fanOutFixture(1);

    $stranger = CreatorFactory::new()->approved()->createOne(['user_id' => User::factory()->createOne()->id]);
    AgencyCreatorRelation::factory()->createOne([
        'agency_id' => Agency::factory()->createOne()->id,
        'creator_id' => $stranger->id,
        'relationship_status' => RelationshipStatus::Roster,
        'is_blacklisted' => false,
    ]);

    enableFanOut();

    expect(fanOut()->send($campaign)->notified)->toBe(1);
    Mail::assertNotQueued(JobPostedMail::class, fn (JobPostedMail $mail): bool => $mail->hasTo(fanOutEmail($stranger)));
});

// ── The two directions of one predicate agree ───────────────────────────────

it('only ever notifies creators who can actually SEE the job', function (): void {
    Mail::fake();
    ['campaign' => $campaign, 'brand' => $brand, 'creators' => $creators] = fanOutFixture(4);

    // Break each leg on a different creator, so the recipient set and the board
    // have to agree case by case rather than in aggregate.
    $creators[0]->forceFill(['application_status' => ApplicationStatus::Pending])->save();
    AgencyCreatorRelation::query()->where('creator_id', $creators[1]->id)
        ->update(['relationship_status' => RelationshipStatus::Ended->value]);
    BrandCreatorBlacklist::factory()->createOne(['brand_id' => $brand->id, 'creator_id' => $creators[2]->id]);

    $visibility = app(JobsBoardVisibility::class);
    $recipients = fanOut()->recipients($campaign, limit: 50);

    // Forwards: everyone selected can see the job.
    foreach ($recipients as $recipient) {
        expect($visibility->findVisible($recipient, $campaign->ulid))
            ->not->toBeNull("recipient {$recipient->id} was notified but cannot see the job");
    }

    // Backwards: everyone excluded genuinely cannot.
    $selected = $recipients->pluck('id')->all();

    foreach ($creators as $creator) {
        if (in_array($creator->id, $selected, true)) {
            continue;
        }

        expect($visibility->findVisible(fanOutFresh($creator), $campaign->ulid))
            ->toBeNull("creator {$creator->id} can see the job but was not notified");
    }

    expect($recipients)->toHaveCount(1);
});

// ── The flip detector (C2) ──────────────────────────────────────────────────

it('dispatches the fan-out job on the false to true listing flip, and stamps listed_at', function (): void {
    Queue::fake();

    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $campaign = Campaign::factory()->forAgency($agency->id)->jobReady()->createOne();

    expect($campaign->listed_at)->toBeNull();

    $this->actingAs($admin)
        ->patchJson("/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}", [
            'listed_on_jobs_board' => true,
        ])
        ->assertOk();

    Queue::assertPushed(SendJobPostedNotificationsJob::class);

    expect($campaign->fresh()?->listed_at)->not->toBeNull();
});

it('does NOT dispatch on an edit that leaves the listing state unchanged', function (bool $listed): void {
    Queue::fake();

    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $campaign = Campaign::factory()->forAgency($agency->id)
        ->{$listed ? 'listed' : 'jobReady'}()
        ->createOne();

    $stampBefore = $campaign->listed_at;

    $this->actingAs($admin)
        ->patchJson("/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}", [
            'name' => 'A totally unrelated rename',
        ])
        ->assertOk();

    Queue::assertNotPushed(SendJobPostedNotificationsJob::class);

    // ⚠ An unrelated edit must not move listed_at — that is the whole reason it
    // exists instead of `updated_at`, which WOULD have moved here.
    expect($campaign->fresh()?->listed_at?->toIso8601String())->toBe($stampBefore?->toIso8601String());
})->with([true, false]);

it('does NOT dispatch or re-stamp when an already-listed campaign is re-sent as listed', function (): void {
    Queue::fake();

    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $campaign = Campaign::factory()->forAgency($agency->id)->listed()->createOne([
        'listed_at' => now()->subMonth(),
    ]);
    $original = $campaign->listed_at;

    $this->actingAs($admin)
        ->patchJson("/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}", [
            'listed_on_jobs_board' => true,
        ])
        ->assertOk();

    Queue::assertNotPushed(SendJobPostedNotificationsJob::class);

    expect($campaign->fresh()?->listed_at?->toIso8601String())->toBe($original?->toIso8601String());
});

it('does NOT dispatch when the campaign is DELISTED', function (): void {
    Queue::fake();

    $agency = Agency::factory()->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $campaign = Campaign::factory()->forAgency($agency->id)->listed()->createOne();

    $this->actingAs($admin)
        ->patchJson("/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}", [
            'listed_on_jobs_board' => false,
        ])
        ->assertOk();

    Queue::assertNotPushed(SendJobPostedNotificationsJob::class);
});

// ── The queued job ──────────────────────────────────────────────────────────

it('runs the fan-out from the queued job and tolerates a deleted campaign', function (): void {
    Mail::fake();
    ['campaign' => $campaign] = fanOutFixture(2);
    enableFanOut();

    (new SendJobPostedNotificationsJob($campaign->id))->handle(fanOut());

    expect(stampCount($campaign))->toBe(2);

    // A campaign deleted between the flip and the worker is a no-op, not a
    // failed job.
    (new SendJobPostedNotificationsJob(999_999))->handle(fanOut());

    expect(CampaignJobNotification::query()->count())->toBe(2);
});
