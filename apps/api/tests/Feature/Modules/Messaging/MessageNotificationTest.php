<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Models\MessageThread;
use App\Modules\Messaging\Services\MessageService;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Sprint 11 (S7, D-7) — the new-message in-app notification emits THROUGH
 * NotificationService::notify() to the COUNTERPARTY, dual-recipient:
 *
 *   - creator sends → fan out to the agency's admins+managers (staff excluded,
 *     the Ch2 fan-out) as `message.received_by_agency`.
 *   - agency sends  → the creator's user as `message.received_by_creator`.
 *
 * System messages (no human sender) never notify (D-4/D-17). Opt-out is honoured
 * by NotificationService (default ON).
 *
 * @return array{agency: Agency, campaign: Campaign, assignment: CampaignAssignment, creatorUser: User, admin: User, manager: User, staff: User}
 */
function messageNotifySetup(): array
{
    $agency = Agency::factory()->createOne();
    $brand = Brand::factory()->forAgency($agency->id)->createOne();
    $campaign = Campaign::factory()->createOne(['agency_id' => $agency->id, 'brand_id' => $brand->id]);

    $admin = User::factory()->agencyAdmin($agency)->createOne();
    $manager = User::factory()->agencyManager($agency)->createOne();
    $staff = User::factory()->agencyStaff($agency)->createOne();

    $creatorUser = User::factory()->createOne();
    $creator = CreatorFactory::new()->createOne(['user_id' => $creatorUser->id]);

    $assignment = CampaignAssignment::factory()->status(AssignmentStatus::Contracted)->createOne([
        'agency_id' => $agency->id,
        'campaign_id' => $campaign->id,
        'brand_id' => $brand->id,
        'creator_id' => $creator->id,
        'invited_by_user_id' => $admin->id,
    ]);

    return compact('agency', 'campaign', 'assignment', 'creatorUser', 'admin', 'manager', 'staff');
}

function creatorSendUrl(CampaignAssignment $assignment): string
{
    return "/api/v1/creators/me/assignments/{$assignment->ulid}/messages";
}

function agencySendUrl(Agency $agency, Campaign $campaign, CampaignAssignment $assignment): string
{
    return "/api/v1/agencies/{$agency->ulid}/campaigns/{$campaign->ulid}/assignments/{$assignment->ulid}/messages";
}

it('a creator send fans out message.received_by_agency to admins+managers (staff excluded)', function (): void {
    $s = messageNotifySetup();

    $this->actingAs($s['creatorUser'])
        ->postJson(creatorSendUrl($s['assignment']), ['body' => 'hello agency'])
        ->assertCreated();

    foreach ([$s['admin'], $s['manager']] as $member) {
        expect(Notification::query()
            ->where('recipient_user_id', $member->id)
            ->where('type', NotificationType::MessageReceivedByAgency->value)
            ->count())->toBe(1);
    }

    // Staff is excluded (the load-bearing fan-out exclusion).
    expect(Notification::query()->where('recipient_user_id', $s['staff']->id)->count())->toBe(0);

    // Actor is the creator; subject is the assignment; data carries the render keys.
    $row = Notification::query()->where('recipient_user_id', $s['manager']->id)->first();
    expect($row?->actor_user_id)->toBe($s['creatorUser']->id)
        ->and($row?->subject_id)->toBe($s['assignment']->id)
        ->and($row?->data['campaign_name'] ?? null)->toBe($s['campaign']->name)
        ->and($row?->data['sender_name'] ?? null)->toBe($s['creatorUser']->name);
});

it('an agency send notifies the creator with message.received_by_creator', function (): void {
    $s = messageNotifySetup();

    $this->actingAs($s['admin'])
        ->postJson(agencySendUrl($s['agency'], $s['campaign'], $s['assignment']), ['body' => 'welcome aboard'])
        ->assertCreated();

    $row = Notification::query()
        ->where('recipient_user_id', $s['creatorUser']->id)
        ->where('type', NotificationType::MessageReceivedByCreator->value)
        ->first();

    expect($row)->not->toBeNull()
        ->and($row?->actor_user_id)->toBe($s['admin']->id)
        ->and($row?->subject_id)->toBe($s['assignment']->id);

    // The sending agency members receive nothing (the creator is the counterparty).
    expect(Notification::query()->where('recipient_user_id', $s['admin']->id)
        ->where('type', NotificationType::MessageReceivedByAgency->value)->count())->toBe(0);
});

it('a system message writes no counterparty notification (D-4/D-17)', function (): void {
    $s = messageNotifySetup();

    $thread = MessageThread::withoutGlobalScopes()->firstOrCreate(
        ['assignment_id' => $s['assignment']->id],
        ['agency_id' => $s['agency']->id],
    );

    app(MessageService::class)->writeSystemMessage($thread, 'assignment.contracted');

    expect(Notification::query()
        ->whereIn('type', [
            NotificationType::MessageReceivedByAgency->value,
            NotificationType::MessageReceivedByCreator->value,
        ])
        ->count())->toBe(0);
});

it('respects an agency member message in_app opt-out — no row for them, the admin still gets one', function (): void {
    $s = messageNotifySetup();

    NotificationPreference::factory()
        ->ofType(NotificationType::MessageReceivedByAgency)
        ->channel(NotificationChannel::InApp)
        ->disabled()
        ->createOne(['user_id' => $s['manager']->id]);

    $this->actingAs($s['creatorUser'])
        ->postJson(creatorSendUrl($s['assignment']), ['body' => 'still here?'])
        ->assertCreated();

    expect(Notification::query()->where('recipient_user_id', $s['manager']->id)->count())->toBe(0)
        ->and(Notification::query()->where('recipient_user_id', $s['admin']->id)
            ->where('type', NotificationType::MessageReceivedByAgency->value)->count())->toBe(1);
});
