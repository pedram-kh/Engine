<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Campaigns\Models\CampaignDraft;
use App\Modules\Creators\Features\PushNotificationsEnabled;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Jobs\SendPushNotificationJob;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationPreference;
use App\Modules\Notifications\Models\UserPushToken;
use App\Modules\Notifications\Push\PushDispatcher;
use App\Modules\Notifications\Push\PushEligibility;
use App\Modules\Notifications\Push\PushMessage;
use App\Modules\Notifications\Push\PushSender;
use App\Modules\Notifications\Push\PushSendResult;
use App\Modules\Notifications\Push\PushTarget;
use App\Modules\Notifications\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Laravel\Pennant\Feature;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| AH-102 C2.4 — PushDispatcher and the push leg of NotificationService::notify()
|--------------------------------------------------------------------------
|
| Queue is faked except where a test is specifically about commit ordering.
| The flag is ON in every test unless the test is about the flag.
*/

/** A sender that records and always delivers (for the commit-ordering test only). */
final class PdRecordingSender implements PushSender
{
    /** @var list<string> */
    public array $tokens = [];

    public function send(PushMessage $message, PushTarget $target, bool $validateOnly = false): PushSendResult
    {
        $this->tokens[] = $target->token;

        return PushSendResult::delivered();
    }
}

/** @return array{0: User, 1: UserPushToken} a creator with ONE live device token */
function pdRecipient(): array
{
    $token = UserPushToken::factory()->create();

    return [User::query()->findOrFail($token->user_id), $token];
}

function pdArm(): void
{
    Feature::activate(PushNotificationsEnabled::NAME);
}

function pdNotifier(): NotificationService
{
    return app(NotificationService::class);
}

/**
 * A realistic `data` bag for a type: the name keys the copy needs plus the
 * thread ids the two message types are keyed on.
 *
 * @return array<string, mixed>
 */
function pdData(NotificationType $type, string $thread = 'THREAD-A'): array
{
    return [
        'campaign_name' => 'Summer Launch',
        'agency_name' => 'Acme Agency',
        'counterparty_name' => 'Acme Agency',
        'sender_name' => 'Acme Agency',
        'assignment_ulid' => $thread,
        'thread_ulid' => $thread,
        'feedback' => 'PRIVATE-FEEDBACK-TEXT',
    ];
}

function pdSetPush(User $user, NotificationType $type, bool $enabled): void
{
    NotificationPreference::query()->create([
        'user_id' => $user->id,
        'type' => $type->value,
        'channel' => 'push',
        'is_enabled' => $enabled,
    ]);
}

function pdSetInApp(User $user, NotificationType $type, bool $enabled): void
{
    NotificationPreference::query()->create([
        'user_id' => $user->id,
        'type' => $type->value,
        'channel' => 'in_app',
        'is_enabled' => $enabled,
    ]);
}

/** @return list<NotificationType> */
function pdEligible(): array
{
    return [...PushEligibility::TOGGLEABLE, ...PushEligibility::ALWAYS_ON];
}

/** @return list<NotificationType> */
function pdIneligible(): array
{
    return array_values(array_filter(
        NotificationType::cases(),
        static fn (NotificationType $t): bool => ! PushEligibility::isEligible($t),
    ));
}

final class PdQueueHolder
{
    public static ?QueueFake $fake = null;
}

function pdFakeQueue(): void
{
    PdQueueHolder::$fake = Queue::fake();
}

/** @return list<SendPushNotificationJob> */
function pdPushed(): array
{
    $jobs = [];
    foreach (PdQueueHolder::$fake?->pushed(SendPushNotificationJob::class) ?? [] as $job) {
        if ($job instanceof SendPushNotificationJob) {
            $jobs[] = $job;
        }
    }

    return $jobs;
}

// =============================================================================
// The dispatch matrix
// =============================================================================

it('pushes every one of the 14 eligible types, once, to the live token, with the type in the payload', function (NotificationType $type): void {
    pdFakeQueue();
    pdArm();
    [$user, $token] = pdRecipient();

    // The job_posted default is OFF per type; opt in for that one so the matrix is about ELIGIBILITY.
    if ($type === NotificationType::CampaignJobPosted) {
        pdSetPush($user, $type, true);
    }

    pdNotifier()->notify($user, $type, data: pdData($type));

    $jobs = pdPushed();
    expect($jobs)->toHaveCount(1)
        ->and($jobs[0]->tokenId)->toBe($token->id)
        ->and($jobs[0]->notificationType)->toBe($type->value)
        ->and($jobs[0]->message->data['type'])->toBe($type->value);
})->with(fn (): array => array_combine(
    array_map(static fn (NotificationType $t): string => $t->value, pdEligible()),
    array_map(static fn (NotificationType $t): array => [$t], pdEligible()),
));

it('the eligible set is exactly 14 and every other type (agency, live_verified, application.rejected, not-yet-live) NEVER pushes', function (): void {
    expect(pdEligible())->toHaveCount(14);

    pdFakeQueue();
    pdArm();
    [$user] = pdRecipient();

    foreach (pdIneligible() as $type) {
        pdNotifier()->notify($user, $type, data: pdData($type));
    }

    Queue::assertNothingPushed();
    expect(pdIneligible())->toContain(NotificationType::AssignmentLiveVerified)
        ->and(pdIneligible())->toContain(NotificationType::CampaignApplicationRejected);
});

it('an ineligible type costs ZERO queries in the push leg', function (): void {
    pdFakeQueue();
    pdArm();
    [$user] = pdRecipient();

    DB::enableQueryLog();
    DB::flushQueryLog();
    app(PushDispatcher::class)->dispatch($user, NotificationType::AssignmentLiveVerified, pdData(NotificationType::AssignmentLiveVerified), null);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toBe([]);
    Queue::assertNothingPushed();
});

// =============================================================================
// Flag
// =============================================================================

it('the flag defaults OFF and, with it off, NOTHING is pushed even for a live token and an eligible type', function (): void {
    pdFakeQueue();
    [$user] = pdRecipient();

    expect(Feature::active(PushNotificationsEnabled::NAME))->toBeFalse();

    pdNotifier()->notify($user, NotificationType::AssignmentInvited, data: pdData(NotificationType::AssignmentInvited));

    Queue::assertNothingPushed();
});

it('the flag gates sending only: the in-app row is written whether it is on or off', function (): void {
    pdFakeQueue();
    [$user] = pdRecipient();

    $off = pdNotifier()->notify($user, NotificationType::AssignmentInvited, data: pdData(NotificationType::AssignmentInvited));
    pdArm();
    $on = pdNotifier()->notify($user, NotificationType::AssignmentInvited, data: pdData(NotificationType::AssignmentInvited));

    expect($off)->toBeInstanceOf(Notification::class)
        ->and($on)->toBeInstanceOf(Notification::class)
        ->and(pdPushed())->toHaveCount(1);
});

// =============================================================================
// Preferences
// =============================================================================

it('a stored push=false preference suppresses a TOGGLEABLE type, and only that type', function (): void {
    pdFakeQueue();
    pdArm();
    [$user] = pdRecipient();
    pdSetPush($user, NotificationType::AssignmentInvited, false);

    pdNotifier()->notify($user, NotificationType::AssignmentInvited, data: pdData(NotificationType::AssignmentInvited));
    Queue::assertNothingPushed();

    pdNotifier()->notify($user, NotificationType::AssignmentDraftApproved, data: pdData(NotificationType::AssignmentDraftApproved));
    expect(pdPushed())->toHaveCount(1);
});

it('a stored push=false row can NOT suppress an ALWAYS-ON type', function (NotificationType $type): void {
    pdFakeQueue();
    pdArm();
    [$user] = pdRecipient();
    pdSetPush($user, $type, false);

    pdNotifier()->notify($user, $type, data: pdData($type));

    expect(pdPushed())->toHaveCount(1);
})->with(PushEligibility::ALWAYS_ON);

it('campaign.job_posted is OFF by default and ON only after an explicit opt-in', function (): void {
    pdFakeQueue();
    pdArm();
    [$user] = pdRecipient();
    $type = NotificationType::CampaignJobPosted;

    pdNotifier()->notify($user, $type, data: pdData($type));
    Queue::assertNothingPushed();

    pdNotifier()->setPreference($user, $type, NotificationChannel::Push, true);
    pdNotifier()->notify($user, $type, data: pdData($type));

    expect(pdPushed())->toHaveCount(1);
});

// =============================================================================
// Push is independent of in-app (BR-10)
// =============================================================================

it('BR-10: in-app MUTED for the type still pushes; notify() returns null and the message carries no notification_ulid', function (): void {
    pdFakeQueue();
    pdArm();
    [$user] = pdRecipient();
    $type = NotificationType::AssignmentDraftApproved;
    pdSetInApp($user, $type, false);

    $result = pdNotifier()->notify($user, $type, data: pdData($type));

    $jobs = pdPushed();
    expect($result)->toBeNull()
        ->and(Notification::query()->where('recipient_user_id', $user->id)->count())->toBe(0)
        ->and($jobs)->toHaveCount(1)
        ->and($jobs[0]->message->data)->not->toHaveKey('notification_ulid');
});

it('BR-10: push muted but in-app on → the in-app row is still written, nothing is pushed', function (): void {
    pdFakeQueue();
    pdArm();
    [$user] = pdRecipient();
    $type = NotificationType::AssignmentDraftApproved;
    pdSetPush($user, $type, false);

    $result = pdNotifier()->notify($user, $type, data: pdData($type));

    expect($result)->toBeInstanceOf(Notification::class);
    Queue::assertNothingPushed();
});

it('with in-app on, the push carries the written row\'s ulid (so a tap can open it)', function (): void {
    pdFakeQueue();
    pdArm();
    [$user] = pdRecipient();

    $row = pdNotifier()->notify($user, NotificationType::AssignmentInvited, data: pdData(NotificationType::AssignmentInvited));

    expect($row)->not->toBeNull()
        ->and(pdPushed()[0]->message->data['notification_ulid'])->toBe($row?->ulid);
});

it('the in-app Notification contract is unchanged: same row, same return type, with or without push', function (): void {
    pdFakeQueue();
    [$user] = pdRecipient();

    $before = pdNotifier()->notify($user, NotificationType::AssignmentInvited, data: ['campaign_name' => 'X']);
    pdArm();
    $after = pdNotifier()->notify($user, NotificationType::AssignmentInvited, data: ['campaign_name' => 'X']);

    expect($before?->data)->toBe($after?->data)
        ->and($before?->type)->toBe($after?->type)
        ->and($before?->recipient_user_id)->toBe($after?->recipient_user_id);
});

// =============================================================================
// Tokens
// =============================================================================

it('no live token → no job (and an invalidated token is not a live token)', function (): void {
    pdFakeQueue();
    pdArm();
    $user = User::factory()->creator()->createOne();

    pdNotifier()->notify($user, NotificationType::AssignmentInvited, data: pdData(NotificationType::AssignmentInvited));
    Queue::assertNothingPushed();

    UserPushToken::factory()->invalidated()->create(['user_id' => $user->id]);
    pdNotifier()->notify($user, NotificationType::AssignmentInvited, data: pdData(NotificationType::AssignmentInvited));
    Queue::assertNothingPushed();
});

it('one job per live token, all carrying the SAME rendered message', function (): void {
    pdFakeQueue();
    pdArm();
    [$user, $first] = pdRecipient();
    $second = UserPushToken::factory()->create(['user_id' => $user->id]);

    pdNotifier()->notify($user, NotificationType::AssignmentInvited, data: pdData(NotificationType::AssignmentInvited));

    $jobs = pdPushed();
    expect($jobs)->toHaveCount(2)
        ->and(array_map(static fn (SendPushNotificationJob $j): int => $j->tokenId, $jobs))->toEqualCanonicalizing([$first->id, $second->id])
        ->and($jobs[0]->message)->toEqual($jobs[1]->message);
});

it('cross-recipient: another user\'s token is NEVER used for this recipient', function (): void {
    pdFakeQueue();
    pdArm();
    [$userA, $tokenA] = pdRecipient();
    [$userB, $tokenB] = pdRecipient();

    pdNotifier()->notify($userA, NotificationType::AssignmentInvited, data: pdData(NotificationType::AssignmentInvited));

    $ids = array_map(static fn (SendPushNotificationJob $j): int => $j->tokenId, pdPushed());
    expect($ids)->toBe([$tokenA->id])
        ->and($ids)->not->toContain($tokenB->id);
    expect($userB->id)->not->toBe($userA->id);
});

it('the job carries only the neutral message — no feedback / data bag text', function (): void {
    pdFakeQueue();
    pdArm();
    [$user] = pdRecipient();

    pdNotifier()->notify($user, NotificationType::AssignmentDraftRejected, data: pdData(NotificationType::AssignmentDraftRejected));

    expect(serialize(pdPushed()[0]))->not->toContain('PRIVATE-FEEDBACK-TEXT');
});

// =============================================================================
// Never throws (BR-11) and runs after commit (BR-8)
// =============================================================================

it('BR-11: a failure anywhere in the push leg is reported and swallowed — the in-app row and the caller\'s transaction survive', function (): void {
    Exceptions::fake();
    pdFakeQueue();
    Feature::define(PushNotificationsEnabled::NAME, static function (): bool {
        throw new RuntimeException('flag backend down');
    });
    [$user] = pdRecipient();

    $row = null;
    DB::transaction(function () use ($user, &$row): void {
        DB::table('users')->where('id', $user->id)->update(['name' => 'written-in-same-transaction']);
        $row = pdNotifier()->notify($user, NotificationType::AssignmentInvited, data: pdData(NotificationType::AssignmentInvited));
    });

    expect($row)->toBeInstanceOf(Notification::class)
        ->and(DB::table('users')->where('id', $user->id)->value('name'))->toBe('written-in-same-transaction');
    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'flag backend down');
});

it('BR-11: a failing throttle store is also swallowed, on the no-row path too', function (): void {
    Exceptions::fake();
    pdFakeQueue();
    pdArm();
    [$user] = pdRecipient();
    pdSetInApp($user, NotificationType::MessageReceivedByCreator, false);
    config(['cache.default' => 'no-such-store']);

    $row = pdNotifier()->notify($user, NotificationType::MessageReceivedByCreator, data: pdData(NotificationType::MessageReceivedByCreator));

    expect($row)->toBeNull();
    Queue::assertNothingPushed();
    Exceptions::assertReported(InvalidArgumentException::class);
});

it('BR-8: a push for a notify() inside a transaction that ROLLS BACK is never sent; one that COMMITS is', function (): void {
    pdArm();
    $sender = new PdRecordingSender;
    app()->instance(PushSender::class, $sender);
    [$user] = pdRecipient();

    try {
        DB::transaction(function () use ($user, $sender): void {
            pdNotifier()->notify($user, NotificationType::AssignmentInvited, data: pdData(NotificationType::AssignmentInvited));
            expect($sender->tokens)->toBe([], 'must not send inside the open transaction');

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect($sender->tokens)->toBe([]);

    DB::transaction(function () use ($user): void {
        pdNotifier()->notify($user, NotificationType::AssignmentInvited, data: pdData(NotificationType::AssignmentInvited));
    });

    expect($sender->tokens)->toHaveCount(1);
});

// =============================================================================
// suppressPush (BR-12)
// =============================================================================

it('suppressPush skips ONLY the push leg: the in-app row is still written', function (): void {
    pdFakeQueue();
    pdArm();
    [$user] = pdRecipient();

    $row = pdNotifier()->notify($user, NotificationType::AssignmentDraftApproved, data: pdData(NotificationType::AssignmentDraftApproved), suppressPush: true);

    expect($row)->toBeInstanceOf(Notification::class);
    Queue::assertNothingPushed();
});

/** @return array{0: Agency, 1: Campaign, 2: CampaignAssignment, 3: User, 4: Creator} */
function pdHandOff(bool $creatorPostsContent): array
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

    CampaignDraft::factory()->createOne([
        'assignment_id' => $assignment->id,
        'submitted_by_creator_id' => $creator->id,
        'version' => 2,
    ]);

    return [$agency, $campaign, $assignment, $admin, $creator];
}

it('BR-12: an approval that ALSO completes the assignment sends ONE push (the completion), but both in-app rows are written', function (): void {
    Mail::fake();
    pdFakeQueue();
    pdArm();
    [$agency, $campaign, $assignment, $admin, $creator] = pdHandOff(creatorPostsContent: false);
    UserPushToken::factory()->create(['user_id' => $creator->user_id]);

    $this->actingAs($admin)
        ->postJson("/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}/assignments/{$assignment->ulid}/approve")
        ->assertOk();

    $types = array_map(static fn (SendPushNotificationJob $j): string => $j->notificationType, pdPushed());
    $rows = Notification::query()->where('recipient_user_id', $creator->user_id)->pluck('type')
        ->map(static fn ($t): string => $t instanceof NotificationType ? $t->value : (string) $t)->all();

    expect($types)->toBe(['assignment.completed_on_approval'])
        ->and($rows)->toContain('assignment.draft_approved')
        ->and($rows)->toContain('assignment.completed_on_approval');
});

it('an approval that does NOT complete the assignment pushes the plain draft_approved', function (): void {
    Mail::fake();
    pdFakeQueue();
    pdArm();
    [$agency, $campaign, $assignment, $admin, $creator] = pdHandOff(creatorPostsContent: true);
    UserPushToken::factory()->create(['user_id' => $creator->user_id]);

    $this->actingAs($admin)
        ->postJson("/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}/assignments/{$assignment->ulid}/approve")
        ->assertOk();

    $types = array_map(static fn (SendPushNotificationJob $j): string => $j->notificationType, pdPushed());
    expect($types)->toBe(['assignment.draft_approved']);

    // AH-103, end to end through the real approve route: the push the device receives
    // carries the REAL assignment ULID the tap opens — not a test-typed one.
    expect(pdPushed()[0]->message->data['assignment_ulid'] ?? null)->toBe($assignment->ulid);
});

// =============================================================================
// Message throttle (BR-13)
// =============================================================================

it('BR-13: two messages on ONE thread within 60s → one push; a second thread gets its own', function (): void {
    pdFakeQueue();
    pdArm();
    [$user] = pdRecipient();
    $type = NotificationType::MessageReceivedByCreator;

    pdNotifier()->notify($user, $type, data: pdData($type, 'ASSIGNMENT-1'));
    pdNotifier()->notify($user, $type, data: pdData($type, 'ASSIGNMENT-1'));
    expect(pdPushed())->toHaveCount(1);

    pdNotifier()->notify($user, $type, data: pdData($type, 'ASSIGNMENT-2'));
    expect(pdPushed())->toHaveCount(2);
});

it('the throttle is per RECIPIENT as well as per thread, and the in-app rows are never throttled', function (): void {
    pdFakeQueue();
    pdArm();
    [$userA] = pdRecipient();
    [$userB] = pdRecipient();
    $type = NotificationType::MessageReceivedByCreator;

    $rows = [];
    $rows[] = pdNotifier()->notify($userA, $type, data: pdData($type, 'SAME-THREAD'));
    $rows[] = pdNotifier()->notify($userA, $type, data: pdData($type, 'SAME-THREAD'));
    $rows[] = pdNotifier()->notify($userB, $type, data: pdData($type, 'SAME-THREAD'));

    expect(pdPushed())->toHaveCount(2)
        ->and(array_filter($rows))->toHaveCount(3);
});

it('the throttle window is ~60s: a message after it pushes again', function (): void {
    pdFakeQueue();
    pdArm();
    [$user] = pdRecipient();
    $type = NotificationType::MessageRelationshipReceivedByCreator;

    pdNotifier()->notify($user, $type, data: pdData($type, 'REL-1'));
    $this->travel(PushDispatcher::THROTTLE_SECONDS - 5)->seconds();
    pdNotifier()->notify($user, $type, data: pdData($type, 'REL-1'));
    expect(pdPushed())->toHaveCount(1);

    $this->travel(10)->seconds();
    pdNotifier()->notify($user, $type, data: pdData($type, 'REL-1'));
    expect(pdPushed())->toHaveCount(2);
});

it('non-message types are never throttled', function (): void {
    pdFakeQueue();
    pdArm();
    [$user] = pdRecipient();

    pdNotifier()->notify($user, NotificationType::AssignmentInvited, data: pdData(NotificationType::AssignmentInvited));
    pdNotifier()->notify($user, NotificationType::AssignmentInvited, data: pdData(NotificationType::AssignmentInvited));

    expect(pdPushed())->toHaveCount(2);
});

it('a throttle slot is NOT spent when nothing is sent (no token / push muted / flag off)', function (): void {
    pdFakeQueue();
    $type = NotificationType::MessageReceivedByCreator;
    $user = User::factory()->creator()->createOne();

    pdArm();
    pdNotifier()->notify($user, $type, data: pdData($type, 'T-1'));        // no token
    UserPushToken::factory()->create(['user_id' => $user->id]);
    pdNotifier()->notify($user, $type, data: pdData($type, 'T-1'));        // token now exists

    expect(pdPushed())->toHaveCount(1);
});

it('the throttle key and the collapse key are the same value', function (): void {
    pdFakeQueue();
    pdArm();
    [$user] = pdRecipient();
    $type = NotificationType::MessageReceivedByCreator;

    pdNotifier()->notify($user, $type, data: pdData($type, 'ASSIGNMENT-9'));

    expect(pdPushed()[0]->message->collapseKey)->toBe('assignment:ASSIGNMENT-9')
        ->and(Cache::has('push:throttle:'.$user->id.':assignment:ASSIGNMENT-9'))->toBeTrue();
});

it('the push throttle never touches the message EMAIL debounce (neither side references the other)', function (): void {
    $dispatcher = (string) file_get_contents(app_path('Modules/Notifications/Push/PushDispatcher.php'));
    $debounce = (string) file_get_contents(app_path('Modules/Messaging/Services/DebouncedMessageMailer.php'));

    expect($dispatcher)->not->toContain('DebouncedMessageMailer')
        ->and($dispatcher)->not->toContain('message_email_debounces')
        ->and($dispatcher)->not->toContain('MessageEmailDebounce')
        ->and($debounce)->not->toContain('Push');
});
