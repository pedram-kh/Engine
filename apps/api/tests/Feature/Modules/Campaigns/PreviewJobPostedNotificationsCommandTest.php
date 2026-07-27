<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignJobNotification;
use App\Modules\Campaigns\Services\JobPostedFanOutService;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Creators\Features\JobPostedNotificationsEnabled;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Pennant\Feature;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * S7 — `campaigns:preview-job-notifications` (AH-056, D6).
 *
 * The command is the only way an operator learns the size of a fan-out before
 * releasing it, and the only way a capped roster ever finishes draining. Both
 * of those are load-bearing, so both are tested here rather than left to a
 * manual console session:
 *
 *  - `--dry-run` mutates NOTHING (asserted against both tables plus the mail
 *    fake) and ignores the flag, so the number an operator reads pre-flip is
 *    the number they get post-flip;
 *  - repeated real runs drain the remainder without ever notifying anyone
 *    twice;
 *  - a bad `--limit` or an unknown ULID fails loudly rather than running
 *    unbounded or silently doing nothing.
 */

/**
 * A listed campaign with `$rosterSize` eligible recipients.
 *
 * @return array{campaign: Campaign, creators: list<int>}
 */
function commandFixture(int $rosterSize = 3): array
{
    $agency = Agency::factory()->createOne(['name' => 'Bright Harbour']);
    $brand = Brand::factory()->forAgency($agency->id)->createOne();

    $campaign = Campaign::factory()->listed()->createOne([
        'agency_id' => $agency->id,
        'brand_id' => $brand->id,
        'name' => 'Autumn UGC push',
        'ends_at' => null,
    ]);

    $ids = [];

    foreach (range(1, $rosterSize) as $index) {
        $user = User::factory()->createOne();
        $creator = CreatorFactory::new()->approved()->createOne(['user_id' => $user->id]);

        AgencyCreatorRelation::factory()->createOne([
            'agency_id' => $agency->id,
            'creator_id' => $creator->id,
            'relationship_status' => RelationshipStatus::Roster,
            'is_blacklisted' => false,
            'created_at' => now()->subDays(100 - $index),
        ]);

        $ids[] = $creator->id;
    }

    return ['campaign' => $campaign, 'creators' => $ids];
}

// ── --dry-run (D6: the pre-flip ritual) ─────────────────────────────────────

it('previews the volume with the flag OFF and writes nothing', function (): void {
    Mail::fake();
    ['campaign' => $campaign] = commandFixture();

    expect(Feature::active(JobPostedNotificationsEnabled::NAME))->toBeFalse();

    $this->artisan('campaigns:preview-job-notifications', [
        'campaign' => $campaign->ulid,
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('[dry-run] "Autumn UGC push": would notify 3 creator(s), 0 would remain after this run (cap 50). No changes made.')
        ->assertSuccessful();

    // The mutation-free claim, asserted against every surface a send touches.
    expect(CampaignJobNotification::query()->count())->toBe(0)
        ->and(Notification::query()->count())->toBe(0);
    Mail::assertNothingQueued();
});

it('reports the remainder a capped run would leave behind', function (): void {
    Mail::fake();
    ['campaign' => $campaign] = commandFixture(5);

    $this->artisan('campaigns:preview-job-notifications', [
        'campaign' => $campaign->ulid,
        '--dry-run' => true,
        '--limit' => '2',
    ])
        ->expectsOutputToContain('would notify 2 creator(s), 3 would remain')
        ->assertSuccessful();

    expect(CampaignJobNotification::query()->count())->toBe(0);
});

it('previews the same number the send then delivers', function (): void {
    Mail::fake();
    ['campaign' => $campaign] = commandFixture(4);

    // Read the pre-flip number...
    $preview = app(JobPostedFanOutService::class)->preview($campaign, 3);

    // ...flip, run for real at the same cap...
    Feature::activate(JobPostedNotificationsEnabled::NAME);
    $this->artisan('campaigns:preview-job-notifications', [
        'campaign' => $campaign->ulid,
        '--limit' => '3',
    ])->assertSuccessful();

    // ...and the promise held.
    expect($preview->notified)->toBe(3)
        ->and(CampaignJobNotification::query()->count())->toBe(3);
});

// ── The real run + the drain (D6) ───────────────────────────────────────────

it('is an explicit no-op — not an error — while the flag is OFF', function (): void {
    Mail::fake();
    ['campaign' => $campaign] = commandFixture();

    $this->artisan('campaigns:preview-job-notifications', ['campaign' => $campaign->ulid])
        ->expectsOutputToContain('job_posted_notifications_enabled is OFF')
        ->assertSuccessful();

    expect(CampaignJobNotification::query()->count())->toBe(0);
    Mail::assertNothingQueued();
});

it('drains a capped roster over repeated runs, notifying nobody twice', function (): void {
    Mail::fake();
    ['campaign' => $campaign, 'creators' => $creators] = commandFixture(5);
    Feature::activate(JobPostedNotificationsEnabled::NAME);

    $this->artisan('campaigns:preview-job-notifications', [
        'campaign' => $campaign->ulid,
        '--limit' => '2',
    ])
        ->expectsOutputToContain('notified 2 creator(s)')
        // The remainder is announced, not left for the operator to infer.
        ->expectsOutputToContain('3 creator(s) still un-notified')
        ->assertSuccessful();

    $this->artisan('campaigns:preview-job-notifications', [
        'campaign' => $campaign->ulid,
        '--limit' => '2',
    ])->assertSuccessful();

    $this->artisan('campaigns:preview-job-notifications', [
        'campaign' => $campaign->ulid,
        '--limit' => '2',
    ])
        ->expectsOutputToContain('notified 1 creator(s)')
        ->assertSuccessful();

    // Five recipients, five stamps, one apiece — the drain is complete and the
    // once-only guarantee survived three runs.
    expect(CampaignJobNotification::query()->count())->toBe(5)
        ->and(CampaignJobNotification::query()->distinct()->count('creator_id'))->toBe(5);
    Mail::assertQueuedCount(5);

    // A fourth run has nothing left to do.
    $this->artisan('campaigns:preview-job-notifications', [
        'campaign' => $campaign->ulid,
        '--limit' => '2',
    ])
        ->expectsOutputToContain('notified 0 creator(s)')
        ->assertSuccessful();

    expect(CampaignJobNotification::query()->count())->toBe(5)
        ->and($creators)->toHaveCount(5);
});

// ── Loud failure (the nudge-command precedent) ──────────────────────────────

it('fails loudly on an unknown campaign ULID', function (): void {
    $this->artisan('campaigns:preview-job-notifications', ['campaign' => '01JZZZNOTACAMPAIGN'])
        ->expectsOutputToContain('No campaign found for ULID')
        ->assertFailed();
});

it('fails loudly on a non-numeric or non-positive --limit', function (string $limit): void {
    Mail::fake();
    ['campaign' => $campaign] = commandFixture();
    Feature::activate(JobPostedNotificationsEnabled::NAME);

    $this->artisan('campaigns:preview-job-notifications', [
        'campaign' => $campaign->ulid,
        '--limit' => $limit,
    ])
        ->expectsOutputToContain('--limit must be a positive integer.')
        ->assertFailed();

    // A rejected limit must not have half-run the fan-out.
    expect(CampaignJobNotification::query()->count())->toBe(0);
    Mail::assertNothingQueued();
})->with(['0', '-1', 'all', '2.5', '']);

it('reaches a campaign in any agency — the console has no tenancy context', function (): void {
    Mail::fake();
    ['campaign' => $mine] = commandFixture(1);
    ['campaign' => $theirs] = commandFixture(1);
    Feature::activate(JobPostedNotificationsEnabled::NAME);

    // Two different agencies; both resolvable by an operator.
    expect($mine->agency_id)->not->toBe($theirs->agency_id);

    $this->artisan('campaigns:preview-job-notifications', ['campaign' => $theirs->ulid])
        ->expectsOutputToContain('notified 1 creator(s)')
        ->assertSuccessful();

    expect(CampaignJobNotification::query()->where('campaign_id', $theirs->id)->count())->toBe(1)
        ->and(CampaignJobNotification::query()->where('campaign_id', $mine->id)->count())->toBe(0);
});

it('sends nothing for a campaign that is no longer listed', function (): void {
    Mail::fake();
    ['campaign' => $campaign] = commandFixture();
    Feature::activate(JobPostedNotificationsEnabled::NAME);

    $campaign->forceFill(['listed_on_jobs_board' => false])->save();

    $this->artisan('campaigns:preview-job-notifications', [
        'campaign' => $campaign->ulid,
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('would notify 0 creator(s)')
        ->assertSuccessful();

    $this->artisan('campaigns:preview-job-notifications', ['campaign' => $campaign->ulid])
        ->expectsOutputToContain('notified 0 creator(s)')
        ->assertSuccessful();

    expect(CampaignJobNotification::query()->count())->toBe(0);
    Mail::assertNothingQueued();
});
