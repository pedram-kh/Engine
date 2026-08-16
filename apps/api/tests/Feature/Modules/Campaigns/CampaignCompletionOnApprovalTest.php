<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Boards\Models\BoardCard;
use App\Modules\Boards\Services\BoardService;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Enums\DraftReviewStatus;
use App\Modules\Campaigns\Events\AssignmentTransitioned;
use App\Modules\Campaigns\Exceptions\AssignmentTransitionException;
use App\Modules\Campaigns\Jobs\VerifyPostedContentJob;
use App\Modules\Campaigns\Mail\AssignmentCompletedOnApprovalMail;
use App\Modules\Campaigns\Mail\DraftReviewedMail;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Campaigns\Models\CampaignDraft;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Models\Message;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * AH-069 D3 — the approval that completes the assignment.
 *
 * The two things this file is really for:
 *
 *   1. The §5.34 pair. A toggle-OFF campaign's approval lands in
 *      `completed_on_approval`; a toggle-ON campaign's approval lands in
 *      `approved` and STAYS there. The second half is the one that protects
 *      every campaign that exists today — the new terminal must be unreachable
 *      for them, not merely unusual.
 *   2. The ONE-TRANSACTION guarantee. The approval and the completion commit
 *      together or not at all. If the completion throws, the assignment is still
 *      `draft_submitted` and the draft is still unreviewed — there is no
 *      approved-but-half-completed state, and no draft carrying a review its
 *      assignment never got.
 *
 * @return array{0: Agency, 1: Campaign, 2: CampaignAssignment, 3: CampaignDraft, 4: User}
 */
function handOffSetup(bool $creatorPostsContent): array
{
    $agency = Agency::factory()->createOne();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();
    $campaign = Campaign::factory()->createOne([
        'agency_id' => $agency->id,
        'brand_id' => $brand->id,
        'creator_posts_content' => $creatorPostsContent,
    ]);
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $creator = Creator::factory()->approved()->createOne();

    $assignment = CampaignAssignment::factory()->status(AssignmentStatus::DraftSubmitted)->createOne([
        'agency_id' => $agency->id,
        'campaign_id' => $campaign->id,
        'brand_id' => $brand->id,
        'creator_id' => $creator->id,
        'invited_by_user_id' => $admin->id,
        'submitted_draft_at' => now(),
    ]);

    $draft = CampaignDraft::factory()->createOne([
        'assignment_id' => $assignment->id,
        'submitted_by_creator_id' => $creator->id,
        'version' => 2,
    ]);

    return [$agency, $campaign, $assignment, $draft, $admin];
}

function approveUrl(Agency $agency, Campaign $campaign, CampaignAssignment $assignment): string
{
    return "/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}/assignments/{$assignment->ulid}/approve";
}

// ── The §5.34 pair ──────────────────────────────────────────────────────────

it('approving on a hand-off campaign completes the assignment in one call', function (): void {
    Mail::fake();
    [$agency, $campaign, $assignment, $draft, $admin] = handOffSetup(creatorPostsContent: false);

    $this->actingAs($admin)
        ->postJson(approveUrl($agency, $campaign, $assignment))
        ->assertOk();

    expect($assignment->fresh()?->status)->toBe(AssignmentStatus::CompletedOnApproval);

    // The draft's own trail still records an APPROVAL — the completion is what
    // happened to the assignment, not a different verdict on the draft.
    $draft->refresh();
    expect($draft->review_status)->toBe(DraftReviewStatus::Approved)
        ->and($draft->reviewed_at)->not->toBeNull();
});

it('writes BOTH audit rows, approval first, and flags the approval as completing', function (): void {
    Mail::fake();
    [$agency, $campaign, $assignment, , $admin] = handOffSetup(creatorPostsContent: false);

    $this->actingAs($admin)
        ->postJson(approveUrl($agency, $campaign, $assignment))
        ->assertOk();

    $rows = AuditLog::query()
        ->where('subject_id', $assignment->id)
        ->whereIn('action', ['assignment.draft_approved', 'assignment.completed_on_approval'])
        ->orderBy('id')
        ->get();

    // Order matters: the trail must read "approved, and that ended it", never
    // the completion appearing out of nowhere.
    expect($rows->map(fn (AuditLog $row): string => $row->action->value)->all())->toBe([
        'assignment.draft_approved',
        'assignment.completed_on_approval',
    ]);

    $approval = $rows->first();
    $completion = $rows->last();

    // The Q3 flag lands in the approval's metadata — which is how the trail
    // explains why the draft-approved EMAIL is missing.
    expect($approval?->metadata['completes_on_approval'] ?? null)->toBeTrue()
        ->and($approval?->metadata['version'] ?? null)->toBe(2)
        ->and($completion?->metadata['from'] ?? null)->toBe('approved')
        ->and($completion?->metadata['to'] ?? null)->toBe('completed_on_approval')
        ->and($completion?->metadata['version'] ?? null)->toBe(2)
        // The flag is NOT copied onto the completion — it is an instruction to
        // the approval's listener, not a property of the completion.
        ->and($completion?->metadata)->not->toHaveKey('completes_on_approval');
});

it('approving on a posting campaign stops at approved — the new terminal is unreachable', function (): void {
    Mail::fake();
    [$agency, $campaign, $assignment, , $admin] = handOffSetup(creatorPostsContent: true);

    $this->actingAs($admin)
        ->postJson(approveUrl($agency, $campaign, $assignment))
        ->assertOk();

    expect($assignment->fresh()?->status)->toBe(AssignmentStatus::Approved);

    expect(AuditLog::query()
        ->where('subject_id', $assignment->id)
        ->where('action', 'assignment.completed_on_approval')
        ->exists())->toBeFalse();

    // And no flag was threaded, so nothing downstream behaves differently.
    $approval = AuditLog::query()
        ->where('subject_id', $assignment->id)
        ->where('action', 'assignment.draft_approved')
        ->firstOrFail();

    expect($approval->metadata)->not->toHaveKey('completes_on_approval');
});

it('a campaign created without naming the toggle behaves as a posting campaign (Q1 safety floor)', function (): void {
    // The §5.34 third case: no explicit value anywhere. The column default
    // decides, and it decides in favour of today's lifecycle.
    Mail::fake();

    $agency = Agency::factory()->createOne();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();
    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $creator = Creator::factory()->approved()->createOne();

    // Built through the model with the toggle unnamed.
    $campaign = Campaign::query()->create([
        'agency_id' => $agency->id,
        'brand_id' => $brand->id,
        'created_by_user_id' => $admin->id,
        'name' => 'Unnamed toggle',
        'objective' => 'ugc',
        'budget_minor_units' => 1000,
        'budget_currency' => 'EUR',
    ]);

    expect($campaign->creator_posts_content)->toBeTrue();

    $assignment = CampaignAssignment::factory()->status(AssignmentStatus::DraftSubmitted)->createOne([
        'agency_id' => $agency->id,
        'campaign_id' => $campaign->id,
        'brand_id' => $brand->id,
        'creator_id' => $creator->id,
        'invited_by_user_id' => $admin->id,
        'submitted_draft_at' => now(),
    ]);

    CampaignDraft::factory()->createOne([
        'assignment_id' => $assignment->id,
        'submitted_by_creator_id' => $creator->id,
        'version' => 1,
    ]);

    $this->actingAs($admin)
        ->postJson(approveUrl($agency, $campaign, $assignment))
        ->assertOk();

    expect($assignment->fresh()?->status)->toBe(AssignmentStatus::Approved);
});

// ── Q3: two in-app rows, exactly one email ──────────────────────────────────

it('sends ONE email for the whole click — the completion mail, not the approval mail', function (): void {
    Mail::fake();
    [$agency, $campaign, $assignment, , $admin] = handOffSetup(creatorPostsContent: false);

    $this->actingAs($admin)
        ->postJson(approveUrl($agency, $campaign, $assignment))
        ->assertOk();

    // The suppressed half: the draft-approved mail does NOT go out, because the
    // completion mail a moment later carries the same news plus the ending.
    Mail::assertNotQueued(DraftReviewedMail::class);
    Mail::assertQueued(AssignmentCompletedOnApprovalMail::class, 1);
});

it('still writes BOTH in-app rows — the trail keeps the approval', function (): void {
    Mail::fake();
    [$agency, $campaign, $assignment, , $admin] = handOffSetup(creatorPostsContent: false);
    $creatorUser = $assignment->creator?->user;

    $this->actingAs($admin)
        ->postJson(approveUrl($agency, $campaign, $assignment))
        ->assertOk();

    // Suppressing the EMAIL must not suppress the record. The creator's history
    // has to show that the draft was approved, not only that it ended.
    $types = Notification::query()
        ->where('recipient_user_id', $creatorUser?->id)
        ->orderBy('id')
        ->pluck('type')
        ->map(fn (NotificationType $type): string => $type->value)
        ->all();

    expect($types)->toBe([
        'assignment.draft_approved',
        'assignment.completed_on_approval',
    ]);
});

it('changes nothing on a posting campaign — one approval mail, one in-app row', function (): void {
    // The other half of the Q3 pin. The flag's ABSENCE must leave today's
    // behaviour byte-for-byte where it was, which is what makes this chunk's
    // notification change zero-impact for every campaign that exists.
    Mail::fake();
    [$agency, $campaign, $assignment, , $admin] = handOffSetup(creatorPostsContent: true);
    $creatorUser = $assignment->creator?->user;

    $this->actingAs($admin)
        ->postJson(approveUrl($agency, $campaign, $assignment))
        ->assertOk();

    Mail::assertQueued(DraftReviewedMail::class, 1);
    Mail::assertQueued(DraftReviewedMail::class, fn (DraftReviewedMail $m): bool => $m->outcome === 'approved');
    Mail::assertNotQueued(AssignmentCompletedOnApprovalMail::class);

    $types = Notification::query()
        ->where('recipient_user_id', $creatorUser?->id)
        ->pluck('type')
        ->map(fn (NotificationType $type): string => $type->value)
        ->all();

    expect($types)->toBe(['assignment.draft_approved']);
});

// ── D5: the rest of the seven-listener sweep ────────────────────────────────

it('writes the truthful closing system message into the assignment thread', function (): void {
    Mail::fake();
    [$agency, $campaign, $assignment, , $admin] = handOffSetup(creatorPostsContent: false);

    $this->actingAs($admin)
        ->postJson(approveUrl($agency, $campaign, $assignment))
        ->assertOk();

    $keys = Message::query()
        ->whereNotNull('system_event_key')
        ->orderBy('id')
        ->pluck('system_event_key')
        ->all();

    // Both lines, in order: a thread that stopped at "The draft was approved."
    // would leave both parties waiting for a post that is never coming.
    expect($keys)->toBe([
        'assignment.draft_approved',
        'assignment.completed_on_approval',
    ]);
});

it('does NOT dispatch posted-content verification — nothing was posted', function (): void {
    Mail::fake();
    Bus::fake();
    [$agency, $campaign, $assignment, , $admin] = handOffSetup(creatorPostsContent: false);

    $this->actingAs($admin)
        ->postJson(approveUrl($agency, $campaign, $assignment))
        ->assertOk();

    // DispatchPostedContentVerification gates on the posted verb, so a new verb
    // is a silent no-op. Pinned rather than assumed: dispatching a verification
    // for a post that does not exist would fail the assignment on a check it was
    // never subject to.
    Bus::assertNotDispatched(VerifyPostedContentJob::class);
});

it('moves the board card no further than the approval already did (Q6)', function (): void {
    Mail::fake();
    [$agency, $campaign, $assignment, , $admin] = handOffSetup(creatorPostsContent: false);

    // Provision the board + heal a card for the assignment, then approve.
    $board = app(BoardService::class)->forCampaign($campaign);

    $this->actingAs($admin)
        ->postJson(approveUrl($agency, $campaign, $assignment))
        ->assertOk();

    $card = BoardCard::query()->where('assignment_id', $assignment->id)->first();
    $approvedColumn = $board->columns()->where('name', 'Approved')->first();

    // Q6: no automation for the new verb. The card stays where approval left it
    // — which is emphatically NOT the Posted column, the one column this
    // campaign's board does not even render.
    expect($card)->not->toBeNull()
        ->and($card?->column_id)->toBe($approvedColumn?->id);
});

// ── The ONE-TRANSACTION guarantee ───────────────────────────────────────────

it('rolls the approval back when the chained completion throws — nothing half-advanced', function (): void {
    Mail::fake();
    [$agency, $campaign, $assignment, $draft, $admin] = handOffSetup(creatorPostsContent: false);

    // Both transitions run for real; the SECOND one is made to fail from inside,
    // through a listener on its own transition event. That is a truer probe than
    // a mocked machine (which the `final` class rightly refuses anyway): the
    // event is dispatched inside `commit()`'s transaction, so a listener throwing
    // there exercises the exact boundary this test is about — and proves the
    // boundary encloses the dispatch, not just the row write.
    Event::listen(function (AssignmentTransitioned $event): void {
        if ($event->action === AuditAction::AssignmentCompletedOnApproval) {
            throw AssignmentTransitionException::illegal(
                AssignmentStatus::Approved,
                AssignmentStatus::CompletedOnApproval,
            );
        }
    });

    $this->actingAs($admin)
        ->postJson(approveUrl($agency, $campaign, $assignment))
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'assignment.invalid_transition');

    // The assignment never left the review queue.
    expect($assignment->fresh()?->status)->toBe(AssignmentStatus::DraftSubmitted);

    // The draft trail was written FIRST inside the same transaction, so it must
    // be gone too — a reviewed draft on an unreviewed assignment would be the
    // worst of the possible half-states.
    $draft->refresh();
    expect($draft->review_status)->toBe(DraftReviewStatus::Pending)
        ->and($draft->reviewed_at)->toBeNull()
        ->and($draft->reviewed_by_user_id)->toBeNull();

    // And no audit row survived either — not the approval, not the completion.
    expect(AuditLog::query()
        ->where('subject_id', $assignment->id)
        ->whereIn('action', ['assignment.draft_approved', 'assignment.completed_on_approval'])
        ->exists())->toBeFalse();
});
