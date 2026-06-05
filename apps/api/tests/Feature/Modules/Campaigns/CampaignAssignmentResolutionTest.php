<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Enums\PostedContentVerificationStatus;
use App\Modules\Campaigns\Mail\PostManuallyVerifiedMail;
use App\Modules\Campaigns\Mail\ResubmitRequestedMail;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Campaigns\Models\CampaignPostedContent;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Verification-resolution chunk — the AGENCY resolution surface for a FAILED
 * auto-verification (D-4/D-5/D-6/D-7). Pins: the three actions on a
 * `posted`+failed assignment (manual override / fresh resubmit / in-place
 * nudge), reason-mandatory on the override, the fresh row kept as history, the
 * `review` authz, and the resolvable fail-closed guard.
 *
 * @return array{0: Agency, 1: Campaign, 2: CampaignAssignment, 3: Creator, 4: CampaignPostedContent}
 */
function resolutionSetup(
    AssignmentStatus $status = AssignmentStatus::Posted,
    PostedContentVerificationStatus $verification = PostedContentVerificationStatus::Mismatch,
): array {
    $agency = Agency::factory()->createOne();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();
    $campaign = Campaign::factory()->createOne(['agency_id' => $agency->id, 'brand_id' => $brand->id]);
    $inviter = User::factory()->agencyAdmin($agency)->createOne();
    $creator = Creator::factory()->approved()->createOne();

    $assignment = CampaignAssignment::factory()->status($status)->createOne([
        'agency_id' => $agency->id,
        'campaign_id' => $campaign->id,
        'brand_id' => $brand->id,
        'creator_id' => $creator->id,
        'invited_by_user_id' => $inviter->id,
        'posted_at' => now(),
    ]);

    $posted = CampaignPostedContent::factory()->createOne([
        'assignment_id' => $assignment->id,
        'post_url' => 'https://instagram.com/someoneelse/p/abc',
        'verification_status' => $verification,
    ]);

    return [$agency, $campaign, $assignment, $creator, $posted];
}

function resolutionUrl(Agency $agency, Campaign $campaign, CampaignAssignment $assignment, string $action): string
{
    return "/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}/assignments/{$assignment->ulid}/{$action}";
}

// ── ACT1 manual-verify (the override) ───────────────────────────────────────

it('an admin manually verifies a failed post → manually_verified, audits the DISTINCT override verb with the reason, notifies the creator', function (): void {
    Mail::fake();
    [$agency, $campaign, $assignment] = resolutionSetup();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $this->actingAs($admin)
        ->postJson(resolutionUrl($agency, $campaign, $assignment, 'manually-verify'), ['reason' => 'Checked the link by hand — the post is live and on-brief.'])
        ->assertOk()
        ->assertJsonPath('data.attributes.status', 'manually_verified');

    expect($assignment->fresh()?->status)->toBe(AssignmentStatus::ManuallyVerified);

    $audit = AuditLog::query()->where('action', 'assignment.manually_verified')->where('subject_id', $assignment->id)->first();
    expect($audit)->not->toBeNull()
        ->and($audit?->reason)->toBe('Checked the link by hand — the post is live and on-brief.');
    // The override is NOT a real pass — no live_verified row.
    expect(AuditLog::query()->where('action', 'assignment.live_verified')->where('subject_id', $assignment->id)->exists())->toBeFalse();

    Mail::assertQueued(PostManuallyVerifiedMail::class);
});

it('manual-verify without a reason fails 422', function (): void {
    [$agency, $campaign, $assignment] = resolutionSetup();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $this->actingAs($admin)
        ->postJson(resolutionUrl($agency, $campaign, $assignment, 'manually-verify'), [])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.source.pointer', '/data/attributes/reason');

    expect($assignment->fresh()?->status)->toBe(AssignmentStatus::Posted);
});

// ── ACT2 request-resubmit-fresh ─────────────────────────────────────────────

it('a manager requests a fresh resubmit → approved, keeps the failed post row as history, notifies the creator', function (): void {
    Mail::fake();
    [$agency, $campaign, $assignment, , $posted] = resolutionSetup();
    $manager = User::factory()->agencyManager($agency)->createOne();

    $this->actingAs($manager)
        ->postJson(resolutionUrl($agency, $campaign, $assignment, 'request-resubmit-fresh'), ['feedback' => 'Please post a fresh link from your connected account.'])
        ->assertOk()
        ->assertJsonPath('data.attributes.status', 'approved');

    expect($assignment->fresh()?->status)->toBe(AssignmentStatus::Approved);

    // The failed post is KEPT as history (not deleted).
    expect(CampaignPostedContent::query()->where('id', $posted->id)->exists())->toBeTrue();
    expect(AuditLog::query()->where('action', 'assignment.resubmit_requested')->where('subject_id', $assignment->id)->exists())->toBeTrue();

    Mail::assertQueued(ResubmitRequestedMail::class, fn (ResubmitRequestedMail $m): bool => $m->mode === 'fresh' && $m->feedback === 'Please post a fresh link from your connected account.');
});

// ── ACT3 request-resubmit-in-place (the nudge — no transition) ──────────────

it('staff requests an in-place resubmit → NO transition (stays posted), audits the in-place verb, notifies the creator', function (): void {
    Mail::fake();
    [$agency, $campaign, $assignment] = resolutionSetup();
    $staff = User::factory()->agencyStaff($agency)->createOne();

    $this->actingAs($staff)
        ->postJson(resolutionUrl($agency, $campaign, $assignment, 'request-resubmit-in-place'), ['feedback' => 'The link 404s — please fix it.'])
        ->assertOk()
        ->assertJsonPath('data.attributes.status', 'posted');

    // No transition — the assignment is unchanged.
    expect($assignment->fresh()?->status)->toBe(AssignmentStatus::Posted);

    // The agency-request audit is the distinct in-place verb (no fresh edge).
    expect(AuditLog::query()->where('action', 'assignment.resubmit_requested_in_place')->where('subject_id', $assignment->id)->exists())->toBeTrue();
    expect(AuditLog::query()->where('action', 'assignment.resubmit_requested')->where('subject_id', $assignment->id)->exists())->toBeFalse();

    Mail::assertQueued(ResubmitRequestedMail::class, fn (ResubmitRequestedMail $m): bool => $m->mode === 'in_place');
});

// ── fail-closed: not resolvable ─────────────────────────────────────────────

it('fails closed (422 not_resolvable) when the assignment is not posted', function (): void {
    [$agency, $campaign, $assignment] = resolutionSetup(AssignmentStatus::Approved, PostedContentVerificationStatus::Pending);
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $this->actingAs($admin)
        ->postJson(resolutionUrl($agency, $campaign, $assignment, 'manually-verify'), ['reason' => 'x'])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'assignment.not_resolvable');
});

it('fails closed (422 not_resolvable) when the post has NOT failed verification (pending/verified)', function (string $verification): void {
    [$agency, $campaign, $assignment] = resolutionSetup(AssignmentStatus::Posted, PostedContentVerificationStatus::from($verification));
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $this->actingAs($admin)
        ->postJson(resolutionUrl($agency, $campaign, $assignment, 'manually-verify'), ['reason' => 'x'])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'assignment.not_resolvable');

    expect($assignment->fresh()?->status)->toBe(AssignmentStatus::Posted);
})->with([
    'pending' => ['pending'],
    'verified' => ['verified'],
]);

// ── authz ───────────────────────────────────────────────────────────────────

it('an outsider cannot reach the resolution actions — 404 (no agency leak)', function (): void {
    [$agency, $campaign, $assignment] = resolutionSetup();
    $outsider = User::factory()->createOne();

    $this->actingAs($outsider)
        ->postJson(resolutionUrl($agency, $campaign, $assignment, 'manually-verify'), ['reason' => 'x'])
        ->assertNotFound();

    expect($assignment->fresh()?->status)->toBe(AssignmentStatus::Posted);
});

// ── visibility (D-7) — the index resource exposes verification_status ───────

it('the Creators-tab listing exposes the latest verification_status (drives the posted+failed row action)', function (): void {
    [$agency, $campaign] = resolutionSetup();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $this->actingAs($admin)
        ->getJson("/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}/assignments")
        ->assertOk()
        ->assertJsonPath('data.0.attributes.status', 'posted')
        ->assertJsonPath('data.0.attributes.verification_status', 'mismatch');
});
