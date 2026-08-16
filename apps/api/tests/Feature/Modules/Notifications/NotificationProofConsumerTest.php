<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Events\AssignmentTransitioned;
use App\Modules\Campaigns\Mail\DraftReviewedMail;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Campaigns\Models\CampaignDraft;
use App\Modules\Campaigns\Services\CampaignAssignmentStateMachine;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * The single proof consumer (S11.0 Chunk 1, D-10): the draft-reviewed → creator
 * path emits an in-app notification ALONGSIDE the untouched email. Proven via
 * the Event::fake split (07-TESTING.md §3.1): one test asserts the dispatch
 * path runs, one runs the listener for real and asserts the row is written.
 *
 * @return array{0: Agency, 1: Campaign, 2: CampaignAssignment, 3: Creator, 4: User}
 */
function proofReviewSetup(): array
{
    $agency = Agency::factory()->createOne();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();
    $campaign = Campaign::factory()->createOne(['agency_id' => $agency->id, 'brand_id' => $brand->id]);
    $inviter = User::factory()->agencyAdmin($agency)->createOne();
    $creator = Creator::factory()->approved()->createOne();

    $assignment = CampaignAssignment::factory()->status(AssignmentStatus::DraftSubmitted)->createOne([
        'agency_id' => $agency->id,
        'campaign_id' => $campaign->id,
        'brand_id' => $brand->id,
        'creator_id' => $creator->id,
        'invited_by_user_id' => $inviter->id,
        'submitted_draft_at' => now(),
    ]);

    CampaignDraft::factory()->createOne([
        'assignment_id' => $assignment->id,
        'submitted_by_creator_id' => $creator->id,
        'version' => 1,
    ]);

    return [$agency, $campaign, $assignment, $creator, $inviter];
}

function proofApproveUrl(Agency $agency, Campaign $campaign, CampaignAssignment $assignment): string
{
    return "/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}/assignments/{$assignment->ulid}/approve";
}

it('event path: approving a draft dispatches AssignmentTransitioned (listener wiring)', function (): void {
    Event::fake([AssignmentTransitioned::class]);
    [$agency, $campaign, $assignment] = proofReviewSetup();
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $this->actingAs($admin)
        ->postJson(proofApproveUrl($agency, $campaign, $assignment))
        ->assertOk()
        ->assertJsonPath('meta.code', 'assignment.draft_approved');

    Event::assertDispatched(
        AssignmentTransitioned::class,
        fn (AssignmentTransitioned $e): bool => $e->to === AssignmentStatus::Approved && $e->assignment->is($assignment),
    );
});

it('row path: approving a draft writes an in-app notification for the creator (listener runs for real)', function (): void {
    Mail::fake();
    [$agency, $campaign, $assignment, $creator, $inviter] = proofReviewSetup();

    $this->actingAs($inviter)
        ->postJson(proofApproveUrl($agency, $campaign, $assignment))
        ->assertOk();

    $notification = Notification::query()
        ->where('recipient_user_id', $creator->user_id)
        ->where('type', NotificationType::AssignmentDraftApproved->value)
        ->first();

    expect($notification)->not->toBeNull()
        ->and($notification?->subject_type)->toBe($assignment->getMorphClass())
        ->and($notification?->subject_id)->toBe($assignment->id)
        ->and($notification?->actor_user_id)->toBe($inviter->id)
        ->and($notification?->data['campaign_name'] ?? null)->toBe($campaign->name)
        ->and($notification?->data['assignment_ulid'] ?? null)->toBe($assignment->ulid);

    // The email is untouched — it still queues alongside the in-app row.
    Mail::assertQueued(DraftReviewedMail::class, fn (DraftReviewedMail $m): bool => $m->outcome === 'approved');
});

it('respects the creator\'s in_app opt-out — no row, email still sent', function (): void {
    Mail::fake();
    [$agency, $campaign, $assignment, $creator, $inviter] = proofReviewSetup();

    NotificationPreference::factory()
        ->ofType(NotificationType::AssignmentDraftApproved)
        ->channel(NotificationChannel::InApp)
        ->disabled()
        ->createOne(['user_id' => $creator->user_id]);

    $this->actingAs($inviter)
        ->postJson(proofApproveUrl($agency, $campaign, $assignment))
        ->assertOk();

    expect(Notification::query()->where('recipient_user_id', $creator->user_id)->count())->toBe(0);
    Mail::assertQueued(DraftReviewedMail::class);
});

// ── The draft round on the payload (AH-068, D4) ──────────────────────────────

it('the review payload carries the round, read off the context the controller already sends', function (): void {
    Mail::fake();
    [$agency, $campaign, $assignment, $creator, $inviter] = proofReviewSetup();

    // A second round, so a passing test cannot be explained by a hardcoded 1.
    CampaignDraft::factory()->createOne([
        'assignment_id' => $assignment->id,
        'submitted_by_creator_id' => $creator->id,
        'version' => 2,
    ]);

    $this->actingAs($inviter)
        ->postJson(proofApproveUrl($agency, $campaign, $assignment))
        ->assertOk();

    $notification = Notification::query()
        ->where('recipient_user_id', $creator->user_id)
        ->where('type', NotificationType::AssignmentDraftApproved->value)
        ->first();

    expect($notification?->data['version'] ?? null)->toBe(2);

    // …and the same round reaches the inbox.
    Mail::assertQueued(DraftReviewedMail::class, fn (DraftReviewedMail $m): bool => $m->round === 2);
});

it('omits the round entirely when the machine is driven with no context (Q2)', function (): void {
    Mail::fake();
    [, , $assignment, $creator, $inviter] = proofReviewSetup();

    // A direct machine call cannot invent a round number. The invariant is that
    // the key is ABSENT — not null — so the client can test presence and every
    // row written before this chunk keeps rendering as it always did.
    app(CampaignAssignmentStateMachine::class)->approve($assignment, $inviter);

    $notification = Notification::query()
        ->where('recipient_user_id', $creator->user_id)
        ->where('type', NotificationType::AssignmentDraftApproved->value)
        ->first();

    expect($notification)->not->toBeNull();

    // `data` is nullable on the model; the row's existence is asserted above.
    $data = $notification->data ?? [];

    expect(array_key_exists('version', $data))->toBeFalse()
        // The rest of the payload is untouched by the round's absence.
        ->and($data['assignment_ulid'] ?? null)->toBe($assignment->ulid);

    Mail::assertQueued(DraftReviewedMail::class, fn (DraftReviewedMail $m): bool => $m->round === null);
});

it('queues the review mail in the recipient creator\'s own language', function (): void {
    Mail::fake();
    [$agency, $campaign, $assignment, $creator, $inviter] = proofReviewSetup();

    User::query()->findOrFail($creator->user_id)->update(['preferred_language' => 'pt']);

    $this->actingAs($inviter)
        ->postJson(proofApproveUrl($agency, $campaign, $assignment))
        ->assertOk();

    // The round clause is localized at queue time with the rest of the mail —
    // it must not pin the sender's locale onto the recipient.
    Mail::assertQueued(DraftReviewedMail::class, fn (DraftReviewedMail $m): bool => $m->locale === 'pt');
});
