<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Events\AssignmentTransitioned;
use App\Modules\Campaigns\Mail\ContractAcceptedMail;
use App\Modules\Campaigns\Mail\DraftSubmittedForReviewMail;
use App\Modules\Campaigns\Mail\PostManuallyVerifiedMail;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * S11.0 Chunk 2 (D-2/D-5/D-6) — the agency fan-out + listener retrofit.
 *
 * The two agency-facing transitions (draft-submitted #3, contracted #4) emit
 * ONE in-app row per agency admin/manager (staff EXCLUDED — the load-bearing
 * assertion) while their email stays single-inviter (the D-6 asymmetry). The
 * manual-verify path (#2) emits in-app to the creator alongside its email.
 *
 * The listener runs for real here via event() (it is registered in
 * CampaignsServiceProvider); the §5.2 dispatch-half is already proven by the
 * Ch1 NotificationProofConsumerTest (the same single listener + event).
 *
 * @return array{agency: Agency, campaign: Campaign, inviter: User, manager: User, staff: User, creator: Creator, assignment: CampaignAssignment}
 */
function fanOutSetup(): array
{
    $agency = Agency::factory()->createOne();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();
    $campaign = Campaign::factory()->createOne(['agency_id' => $agency->id, 'brand_id' => $brand->id]);

    $inviter = User::factory()->agencyAdmin($agency)->createOne();
    $manager = User::factory()->agencyManager($agency)->createOne();
    $staff = User::factory()->agencyStaff($agency)->createOne();

    $creator = Creator::factory()->approved()->createOne();

    $assignment = CampaignAssignment::factory()->status(AssignmentStatus::DraftSubmitted)->createOne([
        'agency_id' => $agency->id,
        'campaign_id' => $campaign->id,
        'brand_id' => $brand->id,
        'creator_id' => $creator->id,
        'invited_by_user_id' => $inviter->id,
    ]);

    return compact('agency', 'campaign', 'inviter', 'manager', 'staff', 'creator', 'assignment');
}

it('Agency::notifiableMembers resolves admins+managers and excludes staff', function (): void {
    $s = fanOutSetup();

    $ids = $s['agency']->notifiableMembers()->pluck('id')->all();

    expect($ids)->toContain($s['inviter']->id)
        ->and($ids)->toContain($s['manager']->id)
        ->and($ids)->not->toContain($s['staff']->id);
});

it('Agency::notifiableMembers excludes a soft-deleted membership (a removed member does not leak)', function (): void {
    $s = fanOutSetup();

    // Remove the manager — a soft-delete on the agency_users pivot.
    $s['agency']->memberships()->where('user_id', $s['manager']->id)->firstOrFail()->delete();

    $ids = $s['agency']->notifiableMembers()->pluck('id')->all();

    expect($ids)->toContain($s['inviter']->id)
        ->and($ids)->not->toContain($s['manager']->id)
        ->and($ids)->not->toContain($s['staff']->id);

    // And the emit path honours it — the removed manager receives no row.
    Mail::fake();
    event(new AssignmentTransitioned(
        assignment: $s['assignment'],
        from: AssignmentStatus::Producing,
        to: AssignmentStatus::DraftSubmitted,
        action: AuditAction::AssignmentDraftSubmitted,
        triggeredByUserId: $s['creator']->user_id,
    ));

    expect(Notification::query()->where('recipient_user_id', $s['manager']->id)->count())->toBe(0)
        ->and(Notification::query()->where('recipient_user_id', $s['inviter']->id)->count())->toBe(1);
});

it('draft-submitted fans out in-app to admins+managers (staff excluded), email stays single-inviter', function (): void {
    Mail::fake();
    $s = fanOutSetup();

    event(new AssignmentTransitioned(
        assignment: $s['assignment'],
        from: AssignmentStatus::Producing,
        to: AssignmentStatus::DraftSubmitted,
        action: AuditAction::AssignmentDraftSubmitted,
        triggeredByUserId: $s['creator']->user_id,
    ));

    // N in-app rows — one per admin/manager.
    foreach ([$s['inviter'], $s['manager']] as $member) {
        expect(Notification::query()
            ->where('recipient_user_id', $member->id)
            ->where('type', NotificationType::AssignmentDraftSubmitted->value)
            ->count())->toBe(1);
    }

    // Staff gets nothing — the load-bearing exclusion.
    expect(Notification::query()->where('recipient_user_id', $s['staff']->id)->count())->toBe(0);

    // Actor is the submitting creator (D-3); the assignment is the subject.
    $row = Notification::query()->where('recipient_user_id', $s['manager']->id)->first();
    expect($row?->actor_user_id)->toBe($s['creator']->user_id)
        ->and($row?->subject_type)->toBe($s['assignment']->getMorphClass())
        ->and($row?->subject_id)->toBe($s['assignment']->id)
        ->and($row?->data['campaign_name'] ?? null)->toBe($s['campaign']->name);

    // Email — UNCHANGED: exactly one, to the inviter (D-6). N-in-app / 1-email.
    Mail::assertQueued(DraftSubmittedForReviewMail::class, 1);
    Mail::assertQueued(
        DraftSubmittedForReviewMail::class,
        fn (DraftSubmittedForReviewMail $m): bool => $m->hasTo($s['inviter']->email),
    );
});

it('contracted fans out in-app to admins+managers (staff excluded), email stays single-inviter', function (): void {
    Mail::fake();
    $s = fanOutSetup();

    event(new AssignmentTransitioned(
        assignment: $s['assignment'],
        from: AssignmentStatus::Accepted,
        to: AssignmentStatus::Contracted,
        action: AuditAction::AssignmentContracted,
        triggeredByUserId: $s['creator']->user_id,
    ));

    foreach ([$s['inviter'], $s['manager']] as $member) {
        expect(Notification::query()
            ->where('recipient_user_id', $member->id)
            ->where('type', NotificationType::AssignmentContracted->value)
            ->count())->toBe(1);
    }

    expect(Notification::query()->where('recipient_user_id', $s['staff']->id)->count())->toBe(0);

    Mail::assertQueued(ContractAcceptedMail::class, 1);
    Mail::assertQueued(
        ContractAcceptedMail::class,
        fn (ContractAcceptedMail $m): bool => $m->hasTo($s['inviter']->email),
    );
});

it('manually-verified emits in-app to the creator alongside the untouched email', function (): void {
    Mail::fake();
    $s = fanOutSetup();
    $verifier = $s['inviter'];

    event(new AssignmentTransitioned(
        assignment: $s['assignment'],
        from: AssignmentStatus::Posted,
        to: AssignmentStatus::ManuallyVerified,
        action: AuditAction::AssignmentManuallyVerified,
        triggeredByUserId: $verifier->id,
    ));

    $row = Notification::query()
        ->where('recipient_user_id', $s['creator']->user_id)
        ->where('type', NotificationType::AssignmentManuallyVerified->value)
        ->first();

    expect($row)->not->toBeNull()
        ->and($row?->actor_user_id)->toBe($verifier->id)
        ->and($row?->subject_id)->toBe($s['assignment']->id);

    Mail::assertQueued(PostManuallyVerifiedMail::class, 1);
});

it('a system-driven transition (no triggeredByUserId) resolves a null actor cleanly — no throw, null actor_user_id', function (): void {
    Mail::fake();
    $s = fanOutSetup();

    // The system case (relevant when the deferred #5 verification-failed lands):
    // triggeredByUserId is null, so the resolver must NOT call User::find(null)
    // or fabricate an actor — it resolves to null and writes a null-actor row.
    event(new AssignmentTransitioned(
        assignment: $s['assignment'],
        from: AssignmentStatus::Posted,
        to: AssignmentStatus::ManuallyVerified,
        action: AuditAction::AssignmentManuallyVerified,
        triggeredByUserId: null,
    ));

    $row = Notification::query()
        ->where('recipient_user_id', $s['creator']->user_id)
        ->where('type', NotificationType::AssignmentManuallyVerified->value)
        ->first();

    expect($row)->not->toBeNull()
        ->and($row?->actor_user_id)->toBeNull();
});

it('respects an agency member in_app opt-out — no row for them, email still single-inviter', function (): void {
    Mail::fake();
    $s = fanOutSetup();

    NotificationPreference::factory()
        ->ofType(NotificationType::AssignmentDraftSubmitted)
        ->channel(NotificationChannel::InApp)
        ->disabled()
        ->createOne(['user_id' => $s['manager']->id]);

    event(new AssignmentTransitioned(
        assignment: $s['assignment'],
        from: AssignmentStatus::Producing,
        to: AssignmentStatus::DraftSubmitted,
        action: AuditAction::AssignmentDraftSubmitted,
        triggeredByUserId: $s['creator']->user_id,
    ));

    // The opted-out manager gets no row; the admin still does.
    expect(Notification::query()->where('recipient_user_id', $s['manager']->id)->count())->toBe(0)
        ->and(Notification::query()->where('recipient_user_id', $s['inviter']->id)->count())->toBe(1);

    Mail::assertQueued(DraftSubmittedForReviewMail::class, 1);
});
