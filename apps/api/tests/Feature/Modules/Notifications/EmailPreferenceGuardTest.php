<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Campaigns\Enums\ApplicationRejectionCause;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Events\AssignmentTransitioned;
use App\Modules\Campaigns\Mail\ApplicationAcceptedMail;
use App\Modules\Campaigns\Mail\ApplicationRejectedMail;
use App\Modules\Campaigns\Mail\ApplicationSubmittedMail;
use App\Modules\Campaigns\Mail\AssignmentCompletedOnApprovalMail;
use App\Modules\Campaigns\Mail\ContractAcceptedMail;
use App\Modules\Campaigns\Mail\DraftReviewedMail;
use App\Modules\Campaigns\Mail\DraftSubmittedForReviewMail;
use App\Modules\Campaigns\Mail\InviteReceivedMail;
use App\Modules\Campaigns\Mail\JobPostedMail;
use App\Modules\Campaigns\Mail\PostManuallyVerifiedMail;
use App\Modules\Campaigns\Mail\PostVerifiedMail;
use App\Modules\Campaigns\Models\CampaignApplication;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Campaigns\Services\CampaignApplicationNotifier;
use App\Modules\Campaigns\Services\JobPostedFanOutService;
use App\Modules\Creators\Features\ApplicationNotificationsEnabled;
use App\Modules\Creators\Features\JobPostedNotificationsEnabled;
use App\Modules\Creators\Features\MissingCreatorMailsEnabled;
use App\Modules\Creators\Features\PostVerifiedMailEnabled;
use App\Modules\Creators\Models\Contract;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Mail\NewMessageMail;
use App\Modules\Messaging\Models\MessageEmailDebounce;
use App\Modules\Messaging\Models\MessageThread;
use App\Modules\Messaging\Services\DebouncedMessageMailer;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationPreference;
use App\Modules\Notifications\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Pennant\Feature;
use Tests\Fixtures\JobsBoard\CreatorJobFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * AH-101 (A1) — the email preference guard, BOTH POLARITIES at EVERY send site.
 *
 * The guard ({@see NotificationService::isEmailEnabled()}) is a single method,
 * but the thing under test is not the method — it is that every typed mail site
 * calls it, with the right recipient and the right type. A guard that wrongly
 * returns false silently drops mail; a site that forgets to call it ignores an
 * opt-out. So the contract is asserted as a pair, over all of the sites at once:
 *
 *   - NO ROWS (the production default): every site sends — the guard is inert.
 *   - ONE `email=false` row for type X: exactly site X stops, every other site
 *     still sends. (Q1: a crafted row is honoured, never cleared.)
 *
 * Break-revert: removing the guard call from any one site turns that site's
 * dataset row red (it still sends); making the guard return false turns the
 * no-rows test red.
 *
 * Sites NOT covered here carry no NotificationType and are structurally exempt —
 * pinned by EmailPreferenceGuardArchitectureTest, not by this file.
 */

/**
 * @return array<string, mixed>
 */
function guardWorld(): array
{
    // Application world — a pending application on a listed campaign.
    $applyFixture = CreatorJobFixture::make();
    $agencyAdmin = User::factory()->agencyAdmin($applyFixture->agency)->createOne();
    $application = CampaignApplication::factory()->createOne([
        'campaign_id' => $applyFixture->campaign->id,
        'agency_id' => $applyFixture->agency->id,
        'creator_id' => $applyFixture->creator->id,
    ]);

    // Fan-out world — a second listed campaign + rostered creator, no application.
    $jobFixture = CreatorJobFixture::make();

    // Assignment world — the listener sites.
    $assignmentFixture = CreatorJobFixture::make();
    $inviter = User::factory()->agencyAdmin($assignmentFixture->agency)->createOne();
    $assignment = CampaignAssignment::factory()->status(AssignmentStatus::DraftSubmitted)->createOne([
        'agency_id' => $assignmentFixture->agency->id,
        'campaign_id' => $assignmentFixture->campaign->id,
        'brand_id' => $assignmentFixture->brand->id,
        'creator_id' => $assignmentFixture->creator->id,
        'invited_by_user_id' => $inviter->id,
        'contract_id' => Contract::factory()->create()->id,
    ]);

    foreach ([
        MissingCreatorMailsEnabled::NAME,
        ApplicationNotificationsEnabled::NAME,
        JobPostedNotificationsEnabled::NAME,
        PostVerifiedMailEnabled::NAME,
    ] as $flag) {
        Feature::activate($flag);
    }

    return [
        'application' => $application,
        'applicant' => $applyFixture->user,
        'agencyAdmin' => $agencyAdmin,
        'jobFixture' => $jobFixture,
        'assignment' => $assignment,
        'creatorUser' => $assignmentFixture->user,
        'inviter' => $inviter,
        'messageThreads' => [MessageThread::factory()->create(), MessageThread::factory()->create()],
        'messageRecipient' => User::factory()->creator()->create(),
    ];
}

/**
 * @param  class-string  $mail
 */
function guardSent(string $mail, User $to, ?Closure $where = null): int
{
    return Mail::queued($mail, fn ($m): bool => $m->hasTo($to->email) && ($where === null || $where($m)))->count();
}

function guardEvent(array $w, AuditAction $action): void
{
    event(new AssignmentTransitioned(
        assignment: $w['assignment'],
        from: AssignmentStatus::Producing,
        to: AssignmentStatus::DraftSubmitted,
        action: $action,
        triggeredByUserId: null,
    ));
}

/**
 * Every guarded site: its NotificationType, the user the guard keys on, how to
 * fire it, and how to count what reached that user.
 *
 * @return array<string, array{type: NotificationType, recipient: User, fire: Closure, sent: Closure}>
 */
function guardSites(array $w): array
{
    $reviewed = static fn (string $outcome): Closure => static fn (): int => guardSent(
        DraftReviewedMail::class,
        $w['creatorUser'],
        static fn (DraftReviewedMail $m): bool => $m->outcome === $outcome,
    );

    $listener = static fn (AuditAction $action): Closure => static function () use ($w, $action): void {
        guardEvent($w, $action);
    };

    $notifier = static fn (): CampaignApplicationNotifier => app(CampaignApplicationNotifier::class);

    $message = static fn (int $threadIndex, string $context): Closure => static function () use ($w, $threadIndex, $context): void {
        app(DebouncedMessageMailer::class)->maybeSend(
            $w['messageThreads'][$threadIndex],
            $w['messageRecipient'],
            new NewMessageMail(
                recipientName: 'Maria',
                senderName: 'Bright Harbour',
                context: $context === 'campaign' ? 'campaign' : 'relationship',
                counterpartyName: 'Autumn UGC push',
                assignmentUlid: $context === 'campaign' ? '01ASSIGNMENTULIDXXXXXXXXXX' : null,
            ),
        );
    };

    return [
        'assignment.invited' => [
            'type' => NotificationType::AssignmentInvited,
            'recipient' => $w['creatorUser'],
            'fire' => $listener(AuditAction::AssignmentInvited),
            'sent' => fn (): int => guardSent(InviteReceivedMail::class, $w['creatorUser']),
        ],
        'assignment.completed_on_approval' => [
            'type' => NotificationType::AssignmentCompletedOnApproval,
            'recipient' => $w['creatorUser'],
            'fire' => $listener(AuditAction::AssignmentCompletedOnApproval),
            'sent' => fn (): int => guardSent(AssignmentCompletedOnApprovalMail::class, $w['creatorUser']),
        ],
        'assignment.manually_verified' => [
            'type' => NotificationType::AssignmentManuallyVerified,
            'recipient' => $w['creatorUser'],
            'fire' => $listener(AuditAction::AssignmentManuallyVerified),
            'sent' => fn (): int => guardSent(PostManuallyVerifiedMail::class, $w['creatorUser']),
        ],
        'assignment.live_verified' => [
            'type' => NotificationType::AssignmentLiveVerified,
            'recipient' => $w['creatorUser'],
            'fire' => $listener(AuditAction::AssignmentLiveVerified),
            'sent' => fn (): int => guardSent(PostVerifiedMail::class, $w['creatorUser']),
        ],
        'assignment.draft_approved' => [
            'type' => NotificationType::AssignmentDraftApproved,
            'recipient' => $w['creatorUser'],
            'fire' => $listener(AuditAction::AssignmentDraftApproved),
            'sent' => $reviewed('approved'),
        ],
        'assignment.revision_requested' => [
            'type' => NotificationType::AssignmentRevisionRequested,
            'recipient' => $w['creatorUser'],
            'fire' => $listener(AuditAction::AssignmentRevisionRequested),
            'sent' => $reviewed('revision_requested'),
        ],
        'assignment.draft_rejected' => [
            'type' => NotificationType::AssignmentDraftRejected,
            'recipient' => $w['creatorUser'],
            'fire' => $listener(AuditAction::AssignmentDraftRejected),
            'sent' => $reviewed('rejected'),
        ],
        // Agency-inviter mails — the guard keys on the INVITER, so an agency user
        // is covered mechanically even though no UI surface exposes the row (Q3).
        'assignment.draft_submitted' => [
            'type' => NotificationType::AssignmentDraftSubmitted,
            'recipient' => $w['inviter'],
            'fire' => $listener(AuditAction::AssignmentDraftSubmitted),
            'sent' => fn (): int => guardSent(DraftSubmittedForReviewMail::class, $w['inviter']),
        ],
        'assignment.contracted' => [
            'type' => NotificationType::AssignmentContracted,
            'recipient' => $w['inviter'],
            'fire' => $listener(AuditAction::AssignmentContracted),
            'sent' => fn (): int => guardSent(ContractAcceptedMail::class, $w['inviter']),
        ],
        'message.received_by_creator' => [
            'type' => NotificationType::MessageReceivedByCreator,
            'recipient' => $w['messageRecipient'],
            'fire' => $message(0, 'campaign'),
            'sent' => fn (): int => guardSent(NewMessageMail::class, $w['messageRecipient'], static fn (NewMessageMail $m): bool => $m->context === 'campaign'),
        ],
        'message.relationship_received_by_creator' => [
            'type' => NotificationType::MessageRelationshipReceivedByCreator,
            'recipient' => $w['messageRecipient'],
            'fire' => $message(1, 'relationship'),
            'sent' => fn (): int => guardSent(NewMessageMail::class, $w['messageRecipient'], static fn (NewMessageMail $m): bool => $m->context === 'relationship'),
        ],
        'campaign.job_posted' => [
            'type' => NotificationType::CampaignJobPosted,
            'recipient' => $w['jobFixture']->user,
            'fire' => function () use ($w): void {
                app(JobPostedFanOutService::class)->send($w['jobFixture']->campaign);
            },
            'sent' => fn (): int => guardSent(JobPostedMail::class, $w['jobFixture']->user),
        ],
        'campaign_application.submitted' => [
            'type' => NotificationType::CampaignApplicationSubmitted,
            'recipient' => $w['agencyAdmin'],
            'fire' => fn () => $notifier()->submitted($w['application']),
            'sent' => fn (): int => guardSent(ApplicationSubmittedMail::class, $w['agencyAdmin']),
        ],
        'campaign_application.accepted' => [
            'type' => NotificationType::CampaignApplicationAccepted,
            'recipient' => $w['applicant'],
            'fire' => fn () => $notifier()->accepted($w['application'], '01JASSIGNMENTULID'),
            'sent' => fn (): int => guardSent(ApplicationAcceptedMail::class, $w['applicant']),
        ],
        'campaign_application.rejected' => [
            'type' => NotificationType::CampaignApplicationRejected,
            'recipient' => $w['applicant'],
            'fire' => fn () => $notifier()->rejected($w['application'], ApplicationRejectionCause::AgencyRejected),
            'sent' => fn (): int => guardSent(ApplicationRejectedMail::class, $w['applicant']),
        ],
    ];
}

function guardSiteKeys(): array
{
    // Resolved without a world: only the keys matter for the dataset.
    return [
        'assignment.invited',
        'assignment.completed_on_approval',
        'assignment.manually_verified',
        'assignment.live_verified',
        'assignment.draft_approved',
        'assignment.revision_requested',
        'assignment.draft_rejected',
        'assignment.draft_submitted',
        'assignment.contracted',
        'message.received_by_creator',
        'message.relationship_received_by_creator',
        'campaign.job_posted',
        'campaign_application.submitted',
        'campaign_application.accepted',
        'campaign_application.rejected',
    ];
}

it('NO ROWS — the production default: every guarded site sends (the guard is inert)', function (): void {
    Mail::fake();
    $w = guardWorld();
    $sites = guardSites($w);

    expect(array_keys($sites))->toBe(guardSiteKeys());

    foreach ($sites as $site) {
        ($site['fire'])();
    }

    foreach ($sites as $key => $site) {
        expect(($site['sent'])())->toBe(1, "site {$key} did not send with no preference rows");
    }
});

it('ONE email=false row stops exactly that site, and no other', function (string $stopped): void {
    Mail::fake();
    $w = guardWorld();
    $sites = guardSites($w);

    NotificationPreference::factory()
        ->ofType($sites[$stopped]['type'])
        ->channel(NotificationChannel::Email)
        ->disabled()
        ->createOne(['user_id' => $sites[$stopped]['recipient']->id]);

    foreach ($sites as $site) {
        ($site['fire'])();
    }

    foreach ($sites as $key => $site) {
        expect(($site['sent'])())->toBe(
            $key === $stopped ? 0 : 1,
            $key === $stopped
                ? "site {$key} ignored the recipient's email=false row"
                : "site {$key} was wrongly stopped by the {$stopped} opt-out",
        );
    }
})->with(guardSiteKeys());

it('an email=false row does not remove the in-app row — the channels are independent', function (): void {
    Mail::fake();
    $w = guardWorld();
    $sites = guardSites($w);

    NotificationPreference::factory()
        ->ofType(NotificationType::AssignmentManuallyVerified)
        ->channel(NotificationChannel::Email)
        ->disabled()
        ->createOne(['user_id' => $w['creatorUser']->id]);

    ($sites['assignment.manually_verified']['fire'])();

    expect(guardSent(PostManuallyVerifiedMail::class, $w['creatorUser']))->toBe(0)
        ->and(
            Notification::query()
                ->where('recipient_user_id', $w['creatorUser']->id)
                ->where('type', NotificationType::AssignmentManuallyVerified->value)
                ->count(),
        )->toBe(1);
});

it('an explicit email=true row sends, exactly like no row (absence = ON)', function (): void {
    Mail::fake();
    $w = guardWorld();
    $sites = guardSites($w);

    NotificationPreference::factory()
        ->ofType(NotificationType::AssignmentManuallyVerified)
        ->channel(NotificationChannel::Email)
        ->createOne(['user_id' => $w['creatorUser']->id, 'is_enabled' => true]);

    ($sites['assignment.manually_verified']['fire'])();

    expect(guardSent(PostManuallyVerifiedMail::class, $w['creatorUser']))->toBe(1);
});

it('a suppressed chat mail never touches the debounce table (a later opt-in is not silenced by a phantom window)', function (): void {
    Mail::fake();
    $w = guardWorld();
    $sites = guardSites($w);

    NotificationPreference::factory()
        ->ofType(NotificationType::MessageReceivedByCreator)
        ->channel(NotificationChannel::Email)
        ->disabled()
        ->createOne(['user_id' => $w['messageRecipient']->id]);

    ($sites['message.received_by_creator']['fire'])();

    expect(MessageEmailDebounce::query()->count())->toBe(0);
});

it('the creator model resolves the same user the guard keys on (no creator/user id mix-up)', function (): void {
    $w = guardWorld();

    expect($w['assignment']->creator)->toBeInstanceOf(Creator::class)
        ->and($w['assignment']->creator?->user_id)->toBe($w['creatorUser']->id);
});
