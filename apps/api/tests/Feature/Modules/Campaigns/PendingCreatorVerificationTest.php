<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Enums\PostedContentVerificationStatus;
use App\Modules\Campaigns\Jobs\VerifyPostedContentJob;
use App\Modules\Campaigns\Mail\PostManuallyVerifiedMail;
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
use App\Modules\Identity\Services\AccountDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| AH-104c C1-5 (Q5) — no NEW live verification for a creator who is leaving
|--------------------------------------------------------------------------
|
| Two doors can move a `posted` assignment to a payable state:
|   - the agency's manual verification (HTTP): refused with the neutral 422 `creator.unavailable`;
|   - the automatic post check (a queued job, no agency end): held back, leaving the post `pending`,
|     and re-run when the creator cancels the request. Delayed, never lost.
| What is NOT narrowed: existing threads stay writable and silent, and the other resolution
| actions (resubmit) are unchanged.
*/

/**
 * @return array{0: Agency, 1: Campaign, 2: CampaignAssignment, 3: Creator, 4: CampaignPostedContent, 5: User}
 */
function leavingSetup(PostedContentVerificationStatus $verification = PostedContentVerificationStatus::Mismatch, string $handle = 'creatorhandle', string $url = 'https://instagram.com/creatorhandle/p/abc'): array
{
    $agency = Agency::factory()->createOne();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();
    $campaign = Campaign::factory()->createOne(['agency_id' => $agency->id, 'brand_id' => $brand->id]);
    $inviter = User::factory()->agencyAdmin($agency)->createOne();
    $creator = Creator::factory()->approved()->createOne();
    CreatorSocialAccount::factory()->createOne(['creator_id' => $creator->id, 'platform' => SocialPlatform::Instagram, 'handle' => $handle]);

    $assignment = CampaignAssignment::factory()->status(AssignmentStatus::Posted)->createOne([
        'agency_id' => $agency->id, 'campaign_id' => $campaign->id, 'brand_id' => $brand->id,
        'creator_id' => $creator->id, 'invited_by_user_id' => $inviter->id, 'posted_at' => now(),
    ]);
    $posted = CampaignPostedContent::factory()->createOne([
        'assignment_id' => $assignment->id, 'platform' => SocialPlatform::Instagram->value,
        'post_url' => $url, 'verification_status' => $verification,
    ]);

    return [$agency, $campaign, $assignment, $creator, $posted, $inviter];
}

function leavingMakePending(Creator $creator): User
{
    $user = User::query()->findOrFail($creator->user_id);
    $user->forceFill(['deletion_requested_at' => now()->subDay(), 'deletion_scheduled_for' => now()->addDays(13)])->saveQuietly();

    return $user->refresh();
}

function leavingUrl(Agency $agency, Campaign $campaign, CampaignAssignment $assignment, string $action): string
{
    return "/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}/assignments/{$assignment->ulid}/{$action}";
}

function leavingRunJob(CampaignPostedContent $posted): void
{
    (new VerifyPostedContentJob($posted->ulid))->handle(app(SocialPlatformProvider::class), app(CampaignAssignmentStateMachine::class));
}

// ── the HTTP door ───────────────────────────────────────────────────────────

it('refuses an agency\'s manual verification of a pending creator with the neutral 422, and writes nothing', function (): void {
    Mail::fake();
    [$agency, $campaign, $assignment, $creator] = leavingSetup();
    leavingMakePending($creator);
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $response = $this->actingAs($admin)
        ->postJson(leavingUrl($agency, $campaign, $assignment, 'manually-verify'), ['reason' => 'Checked the link by hand.'])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'creator.unavailable');

    expect(str_contains((string) $response->getContent(), 'delet'))->toBeFalse()
        ->and(str_contains((string) $response->getContent(), 'pending'))->toBeFalse()
        ->and($assignment->fresh()?->status)->toBe(AssignmentStatus::Posted)
        ->and(AuditLog::query()->where('action', AuditAction::AssignmentManuallyVerified)->count())->toBe(0);
    Mail::assertNothingQueued();
});

it('refuses it for an erased creator too, in the same words', function (): void {
    [$agency, $campaign, $assignment, $creator] = leavingSetup();
    $creator->forceFill(['erased_at' => now()])->saveQuietly();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $this->actingAs($admin)
        ->postJson(leavingUrl($agency, $campaign, $assignment, 'manually-verify'), ['reason' => 'Checked the link by hand.'])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'creator.unavailable');

    expect($assignment->fresh()?->status)->toBe(AssignmentStatus::Posted);
});

it('still verifies an ordinary creator by hand (the over-reach negative)', function (): void {
    Mail::fake();
    [$agency, $campaign, $assignment] = leavingSetup();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $this->actingAs($admin)
        ->postJson(leavingUrl($agency, $campaign, $assignment, 'manually-verify'), ['reason' => 'Checked the link by hand.'])
        ->assertOk()
        ->assertJsonPath('data.attributes.status', 'manually_verified');

    Mail::assertQueued(PostManuallyVerifiedMail::class);
});

it('keeps an unresolvable assignment answering as it always did, before the creator is looked at', function (): void {
    [$agency, $campaign, $assignment, $creator] = leavingSetup(PostedContentVerificationStatus::Pending);
    leavingMakePending($creator);
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $this->actingAs($admin)
        ->postJson(leavingUrl($agency, $campaign, $assignment, 'manually-verify'), ['reason' => 'Checked the link by hand.'])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'assignment.not_resolvable');
});

it('does not narrow the other resolution actions: a nudge to a pending creator still goes out (existing work stays writable)', function (): void {
    Mail::fake();
    [$agency, $campaign, $assignment, $creator] = leavingSetup();
    leavingMakePending($creator);
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $this->actingAs($admin)
        ->postJson(leavingUrl($agency, $campaign, $assignment, 'request-resubmit-in-place'), ['feedback' => 'Please fix the link.'])
        ->assertOk();
});

// ── the automatic door ──────────────────────────────────────────────────────

it('holds back the automatic check for a pending creator: the provider is not called, nothing is saved, the post stays pending', function (): void {
    Feature::define(SocialVerificationEnabled::NAME, true);
    Mail::fake();
    [$assignment, $posted, $creator] = (function (): array {
        [, , $assignment, $creator, $posted] = leavingSetup(PostedContentVerificationStatus::Pending);

        return [$assignment, $posted, $creator];
    })();
    leavingMakePending($creator);

    $provider = Mockery::mock(SocialPlatformProvider::class);
    $provider->shouldNotReceive('verifyPostUrl');
    app()->instance(SocialPlatformProvider::class, $provider);

    leavingRunJob($posted);

    expect($posted->fresh()?->verification_status)->toBe(PostedContentVerificationStatus::Pending)
        ->and($posted->fresh()?->verified_at)->toBeNull()
        ->and($assignment->fresh()?->status)->toBe(AssignmentStatus::Posted);
    Mail::assertNothingQueued();
});

it('holds it back for an erased creator too (the erasure cancels the assignment, nothing verifies it)', function (): void {
    Feature::define(SocialVerificationEnabled::NAME, true);
    [, , $assignment, $creator, $posted] = leavingSetup(PostedContentVerificationStatus::Pending);
    $creator->forceFill(['erased_at' => now()])->saveQuietly();

    leavingRunJob($posted);

    expect($posted->fresh()?->verification_status)->toBe(PostedContentVerificationStatus::Pending)
        ->and($assignment->fresh()?->status)->toBe(AssignmentStatus::Posted);
});

it('still verifies an ordinary creator automatically (the over-reach negative)', function (): void {
    Feature::define(SocialVerificationEnabled::NAME, true);
    [, , $assignment, , $posted] = leavingSetup(PostedContentVerificationStatus::Pending);

    leavingRunJob($posted);

    expect($posted->fresh()?->verification_status)->toBe(PostedContentVerificationStatus::Verified)
        ->and($assignment->fresh()?->status)->toBe(AssignmentStatus::LiveVerified);
});

// ── delayed, never lost ─────────────────────────────────────────────────────

it('re-runs the held-back check when the creator cancels, and the assignment then verifies', function (): void {
    Feature::define(SocialVerificationEnabled::NAME, true);
    [, , $assignment, $creator, $posted] = leavingSetup(PostedContentVerificationStatus::Pending);
    $user = leavingMakePending($creator);

    leavingRunJob($posted); // held back
    expect($assignment->fresh()?->status)->toBe(AssignmentStatus::Posted);

    expect(app(AccountDeletionService::class)->cancel($user, null, null))->toBeTrue();

    // The queue is sync under test: the listener's dispatch ran the job.
    expect($posted->fresh()?->verification_status)->toBe(PostedContentVerificationStatus::Verified)
        ->and($assignment->fresh()?->status)->toBe(AssignmentStatus::LiveVerified);
});

it('dispatches the check for each posted assignment whose latest post is still pending, and for nothing else', function (): void {
    Feature::define(SocialVerificationEnabled::NAME, true);
    Queue::fake();
    [, , $held, $creator, $heldPost] = leavingSetup(PostedContentVerificationStatus::Pending);
    $user = leavingMakePending($creator);

    // Same creator: one already-failed post, one verified, one assignment no longer `posted`.
    $failed = CampaignAssignment::factory()->status(AssignmentStatus::Posted)->createOne(['creator_id' => $creator->id]);
    CampaignPostedContent::factory()->createOne(['assignment_id' => $failed->id, 'verification_status' => PostedContentVerificationStatus::Mismatch]);
    $done = CampaignAssignment::factory()->status(AssignmentStatus::LiveVerified)->createOne(['creator_id' => $creator->id]);
    CampaignPostedContent::factory()->createOne(['assignment_id' => $done->id, 'verification_status' => PostedContentVerificationStatus::Pending]);
    // Another creator, pending post: not ours.
    [, , , , $strangerPost] = leavingSetup(PostedContentVerificationStatus::Pending);

    app(AccountDeletionService::class)->cancel($user, null, null);

    Queue::assertPushed(VerifyPostedContentJob::class, 1);
    Queue::assertPushed(VerifyPostedContentJob::class, fn (VerifyPostedContentJob $j): bool => $j->postedContentUlid === $heldPost->ulid);
    Queue::assertNotPushed(VerifyPostedContentJob::class, fn (VerifyPostedContentJob $j): bool => $j->postedContentUlid === $strangerPost->ulid);
    expect($held->id)->not->toBeNull();
});

it('dispatches nothing when the verification flag is off, and nothing on a second cancel of the same request', function (): void {
    Queue::fake();
    [, , , $creator] = leavingSetup(PostedContentVerificationStatus::Pending);
    $user = leavingMakePending($creator);

    Feature::define(SocialVerificationEnabled::NAME, false);
    app(AccountDeletionService::class)->cancel($user, null, null);
    Queue::assertNotPushed(VerifyPostedContentJob::class);

    // Cancelled already: a second cancel is not a cancellation, so no event and no dispatch even with the flag on.
    // (Pennant remembers a resolved flag for the request: flush it, or the flag stays off and this proves nothing.)
    Feature::define(SocialVerificationEnabled::NAME, true);
    Feature::flushCache();
    expect(Feature::active(SocialVerificationEnabled::NAME))->toBeTrue();
    app(AccountDeletionService::class)->cancel($user->refresh(), null, null);
    Queue::assertNotPushed(VerifyPostedContentJob::class);
});

// ── the manifest: no new caller can slip past ───────────────────────────────

it('lists every caller of verifyLive and manuallyVerify, so a new one fails here until it asks the same question', function (): void {
    $allowed = [
        'Modules/Campaigns/Services/CampaignAssignmentStateMachine.php',
        'Modules/Campaigns/Jobs/VerifyPostedContentJob.php',
        'Modules/Campaigns/Http/Controllers/CampaignAssignmentResolutionController.php',
    ];
    $callers = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }
        if (preg_match('/->(verifyLive|manuallyVerify)\(/', (string) file_get_contents($file->getPathname())) === 1) {
            $callers[] = str_replace(app_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
        }
    }
    sort($callers);
    $expected = array_values(array_diff($allowed, ['Modules/Campaigns/Services/CampaignAssignmentStateMachine.php']));
    sort($expected);

    expect($callers)->toBe($expected);

    // And each of the two asks whether the creator is available before it acts.
    $job = (string) file_get_contents(app_path('Modules/Campaigns/Jobs/VerifyPostedContentJob.php'));
    $controller = (string) file_get_contents(app_path('Modules/Campaigns/Http/Controllers/CampaignAssignmentResolutionController.php'));
    expect($job)->toContain('isUnavailableToAgencies()')
        ->and($controller)->toContain('assertAvailableToAgencies()');
});
