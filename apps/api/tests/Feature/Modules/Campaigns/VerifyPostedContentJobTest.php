<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Enums\PostedContentVerificationStatus;
use App\Modules\Campaigns\Jobs\VerifyPostedContentJob;
use App\Modules\Campaigns\Mail\PostVerificationFailedMail;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Campaigns\Models\CampaignPostedContent;
use App\Modules\Campaigns\Services\CampaignAssignmentStateMachine;
use App\Modules\Creators\Enums\SocialPlatform;
use App\Modules\Creators\Features\SocialVerificationEnabled;
use App\Modules\Creators\Integrations\Contracts\SocialPlatformProvider;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Models\CreatorSocialAccount;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Sprint 9 Chunk 2 (D-10/D-11/D-13) — the mock-verification arc-closer.
 *
 * @return array{0: CampaignAssignment, 1: CampaignPostedContent, 2: User}
 */
function postedAssignmentWithHandle(string $handle, string $postUrl): array
{
    $agency = Agency::factory()->createOne();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();
    $campaign = Campaign::factory()->createOne(['agency_id' => $agency->id, 'brand_id' => $brand->id]);
    $inviter = User::factory()->agencyAdmin($agency)->createOne();
    $creator = Creator::factory()->approved()->createOne();

    CreatorSocialAccount::factory()->createOne([
        'creator_id' => $creator->id,
        'platform' => SocialPlatform::Instagram,
        'handle' => $handle,
    ]);

    $assignment = CampaignAssignment::factory()->status(AssignmentStatus::Posted)->createOne([
        'agency_id' => $agency->id,
        'campaign_id' => $campaign->id,
        'brand_id' => $brand->id,
        'creator_id' => $creator->id,
        'invited_by_user_id' => $inviter->id,
    ]);

    $posted = CampaignPostedContent::factory()->createOne([
        'assignment_id' => $assignment->id,
        'platform' => SocialPlatform::Instagram->value,
        'post_url' => $postUrl,
        'verification_status' => PostedContentVerificationStatus::Pending,
    ]);

    return [$assignment, $posted, $inviter];
}

function runVerifyJob(CampaignPostedContent $posted): void
{
    (new VerifyPostedContentJob($posted->ulid))->handle(
        app(SocialPlatformProvider::class),
        app(CampaignAssignmentStateMachine::class),
    );
}

it('verified outcome: stamps the post + drives posted → live_verified (flag ON)', function (): void {
    Feature::define(SocialVerificationEnabled::NAME, true);
    Mail::fake();

    [$assignment, $posted] = postedAssignmentWithHandle('creatorhandle', 'https://instagram.com/creatorhandle/p/abc');

    runVerifyJob($posted);

    $posted->refresh();
    expect($posted->verification_status)->toBe(PostedContentVerificationStatus::Verified)
        ->and($posted->verified_at)->not->toBeNull()
        ->and($posted->platform_post_id)->not->toBeNull();

    expect($assignment->fresh()?->status)->toBe(AssignmentStatus::LiveVerified);

    // Verified → no failure notification.
    Mail::assertNothingQueued();
});

it('mismatch outcome: stays posted + notifies the agency, no machine call (D-13)', function (): void {
    Feature::define(SocialVerificationEnabled::NAME, true);
    Mail::fake();

    [$assignment, $posted, $inviter] = postedAssignmentWithHandle('creatorhandle', 'https://instagram.com/someoneelse/p/abc');

    runVerifyJob($posted);

    $posted->refresh();
    expect($posted->verification_status)->toBe(PostedContentVerificationStatus::Mismatch)
        ->and($posted->verified_at)->toBeNull();

    // The assignment stays posted (no verifyLive).
    expect($assignment->fresh()?->status)->toBe(AssignmentStatus::Posted);

    Mail::assertQueued(PostVerificationFailedMail::class, fn (PostVerificationFailedMail $m): bool => $m->hasTo($inviter->email) && $m->outcome === 'mismatch');
});

it('not_found outcome: stays posted + notifies the agency', function (): void {
    Feature::define(SocialVerificationEnabled::NAME, true);
    Mail::fake();

    [$assignment, $posted, $inviter] = postedAssignmentWithHandle('creatorhandle', 'https://example.com/not-a-post');

    runVerifyJob($posted);

    expect($posted->fresh()?->verification_status)->toBe(PostedContentVerificationStatus::NotFound)
        ->and($assignment->fresh()?->status)->toBe(AssignmentStatus::Posted);

    Mail::assertQueued(PostVerificationFailedMail::class, fn (PostVerificationFailedMail $m): bool => $m->outcome === 'not_found');
});

it('is a no-op when the flag is OFF (break-revert — verifyLive stays gated)', function (): void {
    Feature::define(SocialVerificationEnabled::NAME, false);
    Mail::fake();

    [$assignment, $posted] = postedAssignmentWithHandle('creatorhandle', 'https://instagram.com/creatorhandle/p/abc');

    runVerifyJob($posted);

    expect($posted->fresh()?->verification_status)->toBe(PostedContentVerificationStatus::Pending)
        ->and($assignment->fresh()?->status)->toBe(AssignmentStatus::Posted);
    Mail::assertNothingQueued();
});

it('is idempotent — an already-verified post is not re-processed', function (): void {
    Feature::define(SocialVerificationEnabled::NAME, true);

    [, $posted] = postedAssignmentWithHandle('creatorhandle', 'https://instagram.com/creatorhandle/p/abc');
    $posted->update(['verification_status' => PostedContentVerificationStatus::Verified, 'platform_post_id' => 'existing']);

    runVerifyJob($posted);

    expect($posted->fresh()?->platform_post_id)->toBe('existing');
});

// ── The listener dispatch (D-10) — posted_by_creator dispatches the job ──────

it('the posted_by_creator transition dispatches VerifyPostedContentJob when the flag is ON', function (): void {
    Feature::define(SocialVerificationEnabled::NAME, true);
    Queue::fake();

    [$assignment, $posted] = postedAssignmentWithHandle('creatorhandle', 'https://instagram.com/creatorhandle/p/abc');
    // Reset to approved so markPosted is a legal source.
    $assignment->update(['status' => AssignmentStatus::Approved]);

    app(CampaignAssignmentStateMachine::class)->markPosted($assignment, null, context: [
        'posted_content_id' => $posted->ulid,
        'platform' => $posted->platform,
    ]);

    Queue::assertPushed(VerifyPostedContentJob::class, fn (VerifyPostedContentJob $j): bool => $j->postedContentUlid === $posted->ulid);
});

it('the posted_by_creator transition does NOT dispatch the job when the flag is OFF', function (): void {
    Feature::define(SocialVerificationEnabled::NAME, false);
    Queue::fake();

    [$assignment, $posted] = postedAssignmentWithHandle('creatorhandle', 'https://instagram.com/creatorhandle/p/abc');
    $assignment->update(['status' => AssignmentStatus::Approved]);

    app(CampaignAssignmentStateMachine::class)->markPosted($assignment, null, context: [
        'posted_content_id' => $posted->ulid,
        'platform' => $posted->platform,
    ]);

    Queue::assertNotPushed(VerifyPostedContentJob::class);
});
