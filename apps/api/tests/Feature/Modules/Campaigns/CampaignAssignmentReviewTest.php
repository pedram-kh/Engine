<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Enums\DraftReviewStatus;
use App\Modules\Campaigns\Enums\PostedContentVerificationStatus;
use App\Modules\Campaigns\Mail\DraftReviewedMail;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Campaigns\Models\CampaignDraft;
use App\Modules\Campaigns\Models\CampaignPostedContent;
use App\Modules\Campaigns\Policies\CampaignPolicy;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Sprint 9 Chunk 2 — the agency review surface (D-4/D-5/D-6/D-7). Per-action
 * endpoints, the review trail written in-transaction with the machine drive,
 * the `review` authz (admin + manager + staff), fail-closed source guards, and
 * the reviewed-creator notification.
 *
 * @return array{0: Agency, 1: Campaign, 2: CampaignAssignment, 3: CampaignDraft}
 */
function reviewSetup(AssignmentStatus $status = AssignmentStatus::DraftSubmitted): array
{
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
        'submitted_draft_at' => now(),
    ]);

    $draft = CampaignDraft::factory()->createOne([
        'assignment_id' => $assignment->id,
        'submitted_by_creator_id' => $creator->id,
        'version' => 1,
    ]);

    return [$agency, $campaign, $assignment, $draft];
}

function reviewUrl(Agency $agency, Campaign $campaign, CampaignAssignment $assignment, string $action): string
{
    return "/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}/assignments/{$assignment->ulid}/{$action}";
}

// ── approve ──────────────────────────────────────────────────────────────────

it('an admin approves a submitted draft → approved, writes the trail, audits, notifies the creator', function (): void {
    Mail::fake();
    [$agency, $campaign, $assignment, $draft] = reviewSetup();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $this->actingAs($admin)
        ->postJson(reviewUrl($agency, $campaign, $assignment, 'approve'))
        ->assertOk()
        ->assertJsonPath('meta.code', 'assignment.draft_approved');

    expect($assignment->fresh()?->status)->toBe(AssignmentStatus::Approved);

    $draft->refresh();
    expect($draft->review_status)->toBe(DraftReviewStatus::Approved)
        ->and($draft->reviewed_at)->not->toBeNull()
        ->and($draft->reviewed_by_user_id)->toBe($admin->id);

    expect(AuditLog::query()->where('action', 'assignment.draft_approved')->where('subject_id', $assignment->id)->exists())->toBeTrue();

    Mail::assertQueued(DraftReviewedMail::class, fn (DraftReviewedMail $m): bool => $m->outcome === 'approved');
});

// ── request-revision ──────────────────────────────────────────────────────────

it('a manager requests revision with feedback → revision_requested + the feedback persisted on the trail', function (): void {
    Mail::fake();
    [$agency, $campaign, $assignment, $draft] = reviewSetup();
    $manager = User::factory()->agencyManager($agency)->createOne();

    $this->actingAs($manager)
        ->postJson(reviewUrl($agency, $campaign, $assignment, 'request-revision'), ['review_feedback' => 'Please brighten the lighting.'])
        ->assertOk()
        ->assertJsonPath('meta.code', 'assignment.revision_requested');

    expect($assignment->fresh()?->status)->toBe(AssignmentStatus::RevisionRequested);

    $draft->refresh();
    expect($draft->review_status)->toBe(DraftReviewStatus::RevisionRequested)
        ->and($draft->review_feedback)->toBe('Please brighten the lighting.');

    Mail::assertQueued(DraftReviewedMail::class, fn (DraftReviewedMail $m): bool => $m->outcome === 'revision_requested' && $m->feedback === 'Please brighten the lighting.');
});

it('request-revision without feedback fails 422 (bound to review_feedback)', function (): void {
    [$agency, $campaign, $assignment] = reviewSetup();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $this->actingAs($admin)
        ->postJson(reviewUrl($agency, $campaign, $assignment, 'request-revision'), [])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.source.pointer', '/data/attributes/review_feedback');

    expect($assignment->fresh()?->status)->toBe(AssignmentStatus::DraftSubmitted);
});

// ── reject (the dedicated terminal) ─────────────────────────────────────────

it('staff rejects with a reason → rejected (terminal), trail stamped, audits assignment.draft_rejected, notifies the creator', function (): void {
    Mail::fake();
    [$agency, $campaign, $assignment, $draft] = reviewSetup();
    $staff = User::factory()->agencyStaff($agency)->createOne();

    $this->actingAs($staff)
        ->postJson(reviewUrl($agency, $campaign, $assignment, 'reject'), ['review_feedback' => 'Does not meet the brief.'])
        ->assertOk()
        ->assertJsonPath('meta.code', 'assignment.draft_rejected');

    $fresh = $assignment->fresh();
    expect($fresh?->status)->toBe(AssignmentStatus::Rejected)
        ->and($fresh?->status->isTerminal())->toBeTrue();

    $draft->refresh();
    expect($draft->review_status)->toBe(DraftReviewStatus::Rejected)
        ->and($draft->review_feedback)->toBe('Does not meet the brief.');

    $audit = AuditLog::query()->where('action', 'assignment.draft_rejected')->where('subject_id', $assignment->id)->first();
    expect($audit)->not->toBeNull()
        ->and($audit?->reason)->toBe('Does not meet the brief.');

    Mail::assertQueued(DraftReviewedMail::class, fn (DraftReviewedMail $m): bool => $m->outcome === 'rejected');
});

it('reject without a reason fails 422', function (): void {
    [$agency, $campaign, $assignment] = reviewSetup();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $this->actingAs($admin)
        ->postJson(reviewUrl($agency, $campaign, $assignment, 'reject'), [])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.source.pointer', '/data/attributes/review_feedback');
});

// ── authz + fail-closed ─────────────────────────────────────────────────────

it('a non-member cannot review — rejected fail-closed by tenancy (404, no agency leak)', function (): void {
    // review = admin + manager + staff (every agency role), so the policy's
    // DENY branch is only reachable by a non-member — who never reaches the
    // gate: the tenancy.agency middleware rejects them with a 404 first (not
    // leaking the agency's existence). The three role-positive cases above
    // (admin / manager / staff) prove the allow branch across all roles.
    [$agency, $campaign, $assignment] = reviewSetup();
    $outsider = User::factory()->createOne();

    $this->actingAs($outsider)
        ->postJson(reviewUrl($agency, $campaign, $assignment, 'approve'))
        ->assertNotFound();

    expect($assignment->fresh()?->status)->toBe(AssignmentStatus::DraftSubmitted);
});

it('the review policy ability grants admin + manager + staff', function (): void {
    [$agency, $campaign] = reviewSetup();
    $policy = new CampaignPolicy;

    foreach (['agencyAdmin', 'agencyManager', 'agencyStaff'] as $role) {
        $user = User::factory()->{$role}($agency)->createOne();
        expect($policy->review($user, $campaign))->toBeTrue("{$role} should be able to review");
    }

    // A user with no agency membership is denied at the policy level too.
    expect($policy->review(User::factory()->createOne(), $campaign))->toBeFalse();
});

it('fails closed (422 not_reviewable) when the assignment has no draft awaiting review', function (): void {
    [$agency, $campaign, $assignment] = reviewSetup(AssignmentStatus::Approved);
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $this->actingAs($admin)
        ->postJson(reviewUrl($agency, $campaign, $assignment, 'approve'))
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'assignment.not_reviewable');
});

// ── the agency-side show (D-7) ──────────────────────────────────────────────

it('the agency show returns the assignment with its draft history + posted content', function (): void {
    [$agency, $campaign, $assignment, $draft] = reviewSetup();
    CampaignPostedContent::factory()->createOne([
        'assignment_id' => $assignment->id,
        'verification_status' => PostedContentVerificationStatus::Pending,
    ]);
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $this->actingAs($admin)
        ->getJson("/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}/assignments/{$assignment->ulid}")
        ->assertOk()
        ->assertJsonPath('data.id', $assignment->ulid)
        ->assertJsonPath('data.attributes.status', 'draft_submitted')
        ->assertJsonPath('data.relationships.drafts.0.id', $draft->ulid)
        ->assertJsonCount(1, 'data.relationships.posted_content');
});

it('the agency show emits the invite-offer block (board-drawer detail facelift)', function (): void {
    [$agency, $campaign, $assignment] = reviewSetup();
    $assignment->update([
        'fee_per' => 'script',
        'offer_description' => 'Two hooks, one CTA.',
        'offer_attachment_path' => 'agencies/x/campaigns/y/offer-attachments/z.pdf',
        'offer_attachment_name' => 'brief.pdf',
        'offer_attachment_mime' => 'application/pdf',
        'offer_attachment_size_bytes' => 2048,
    ]);
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $this->actingAs($admin)
        ->getJson("/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}/assignments/{$assignment->ulid}")
        ->assertOk()
        ->assertJsonPath('data.attributes.fee_per', 'script')
        ->assertJsonPath('data.attributes.offer_description', 'Two hooks, one CTA.')
        ->assertJsonPath('data.attributes.offer_attachment.name', 'brief.pdf')
        ->assertJsonPath('data.attributes.offer_attachment.size_bytes', 2048)
        ->assertJsonPath('data.attributes.invited_at', $assignment->invited_at?->toIso8601String());
});

it('404s when the assignment does not belong to the campaign', function (): void {
    [$agency, $campaign] = reviewSetup();
    [, , $otherAssignment] = reviewSetup();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $this->actingAs($admin)
        ->getJson("/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}/assignments/{$otherAssignment->ulid}")
        ->assertNotFound();
});
