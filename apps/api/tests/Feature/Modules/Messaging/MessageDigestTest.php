<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Enums\MessageKind;
use App\Modules\Messaging\Enums\MessageSenderRole;
use App\Modules\Messaging\Mail\UnreadMessagesDigestMail;
use App\Modules\Messaging\Models\MessageThread;
use App\Modules\Messaging\Services\MessageService;
use App\Modules\Messaging\Services\MessageThreadService;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Models\NotificationPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Sprint 11 (S8, D-9) — the daily unread-messages digest, the app's FIRST
 * scheduled command. The digest is the messaging EMAIL channel (D-8: no
 * immediate per-message email; the digest IS the email path, opt-in/default
 * OFF). It does NOT ride NotificationService::notify() — it gates each
 * recipient on isChannelEnabled(…, Digest) itself.
 *
 * ⚠ The load-bearing surface is TENANCY: the digest runs in a console with no
 * agency context (BelongsToAgencyScope is a no-op), so the absence test asserts
 * agency A's digest NEVER reflects agency B's threads (the Ch1 isolation anchor
 * applied to the digest).
 *
 * @return array{agency: Agency, campaign: Campaign, admin: User, creatorUser: User, thread: MessageThread}
 */
function digestSetup(string $campaignName = 'Spring Launch'): array
{
    $agency = Agency::factory()->createOne();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();
    $campaign = Campaign::factory()->createOne([
        'agency_id' => $agency->id,
        'brand_id' => $brand->id,
        'name' => $campaignName,
    ]);
    $admin = User::factory()->agencyAdmin($agency)->createOne();

    $creatorUser = User::factory()->createOne();
    $creator = CreatorFactory::new()->createOne(['user_id' => $creatorUser->id]);

    $assignment = CampaignAssignment::factory()->status(AssignmentStatus::Contracted)->createOne([
        'agency_id' => $agency->id,
        'campaign_id' => $campaign->id,
        'brand_id' => $brand->id,
        'creator_id' => $creator->id,
        'invited_by_user_id' => $admin->id,
    ]);

    $thread = app(MessageThreadService::class)->forAssignment($assignment);

    return compact('agency', 'campaign', 'admin', 'creatorUser', 'thread');
}

function optIntoDigest(User $user, NotificationType $type): void
{
    NotificationPreference::factory()
        ->ofType($type)
        ->channel(NotificationChannel::Digest)
        ->createOne(['user_id' => $user->id, 'is_enabled' => true]);
}

function sendAs(User $sender, MessageSenderRole $role, MessageThread $thread, string $body): void
{
    app(MessageService::class)->sendHumanMessage($thread, $sender, $role, MessageKind::Text, $body, []);
}

it('queues one digest per opted-in user with unread, with the right aggregate', function (): void {
    Mail::fake();
    ['admin' => $admin, 'creatorUser' => $creatorUser, 'thread' => $thread, 'campaign' => $campaign] = digestSetup();

    optIntoDigest($admin, NotificationType::MessageReceivedByAgency);
    optIntoDigest($creatorUser, NotificationType::MessageReceivedByCreator);

    // Creator sends two → the admin has 2 unread; agency sends one → creator has 1.
    sendAs($creatorUser, MessageSenderRole::Creator, $thread, 'c1');
    sendAs($creatorUser, MessageSenderRole::Creator, $thread, 'c2');
    sendAs($admin, MessageSenderRole::AgencyUser, $thread, 'a1');

    $this->artisan('messages:send-digest')->assertExitCode(0);

    Mail::assertQueued(UnreadMessagesDigestMail::class, 2);

    // The admin's digest: 2 unread, one line for the campaign.
    Mail::assertQueued(UnreadMessagesDigestMail::class, function (UnreadMessagesDigestMail $mail) use ($admin, $campaign): bool {
        return $mail->hasTo($admin->email)
            && $mail->totalUnread === 2
            && count($mail->lines) === 1
            && $mail->lines[0]['campaign'] === $campaign->name;
    });

    // The creator's digest: 1 unread.
    Mail::assertQueued(UnreadMessagesDigestMail::class, fn (UnreadMessagesDigestMail $mail): bool => $mail->hasTo($creatorUser->email) && $mail->totalUnread === 1);
});

it('sends NO digest to a user who has unread but has not opted in (default OFF)', function (): void {
    Mail::fake();
    ['admin' => $admin, 'creatorUser' => $creatorUser, 'thread' => $thread] = digestSetup();

    // Neither party opts in; the creator still has an unread from the agency.
    sendAs($admin, MessageSenderRole::AgencyUser, $thread, 'a1');

    $this->artisan('messages:send-digest')->assertExitCode(0);

    Mail::assertNothingQueued();
});

it('sends NO digest to an opted-in user with zero unread', function (): void {
    Mail::fake();
    ['admin' => $admin, 'thread' => $thread] = digestSetup();

    optIntoDigest($admin, NotificationType::MessageReceivedByAgency);

    // The admin's OWN send is never unread for them; no counterparty message.
    sendAs($admin, MessageSenderRole::AgencyUser, $thread, 'a1');

    $this->artisan('messages:send-digest')->assertExitCode(0);

    Mail::assertNothingQueued();
});

it("does NOT let agency A's digest reflect agency B's threads (cross-agency absence)", function (): void {
    Mail::fake();

    $a = digestSetup('Agency A Campaign');
    $b = digestSetup('Agency B Campaign');

    optIntoDigest($a['admin'], NotificationType::MessageReceivedByAgency);

    // Both agencies have unread (their creators each send into their own thread).
    sendAs($a['creatorUser'], MessageSenderRole::Creator, $a['thread'], 'a-msg');
    sendAs($b['creatorUser'], MessageSenderRole::Creator, $b['thread'], 'b-msg-1');
    sendAs($b['creatorUser'], MessageSenderRole::Creator, $b['thread'], 'b-msg-2');

    $this->artisan('messages:send-digest')->assertExitCode(0);

    // Agency A's admin gets exactly ONE digest — reflecting only A's single
    // unread and A's campaign, never B's two unread / B's campaign.
    Mail::assertQueued(UnreadMessagesDigestMail::class, 1);
    Mail::assertQueued(UnreadMessagesDigestMail::class, function (UnreadMessagesDigestMail $mail) use ($a): bool {
        return $mail->hasTo($a['admin']->email)
            && $mail->totalUnread === 1
            && count($mail->lines) === 1
            && $mail->lines[0]['campaign'] === 'Agency A Campaign';
    });
});

it('registers the digest as a daily scheduled command (the app first schedule)', function (): void {
    // schedule:list bootstraps the console kernel's schedule (the withSchedule
    // callback in bootstrap/app.php), so the registration is exercised for real.
    $this->artisan('schedule:list')
        ->expectsOutputToContain('messages:send-digest')
        ->assertExitCode(0);
});
