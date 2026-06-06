<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationPreference;
use App\Modules\Notifications\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * NotificationService emit seam (S11.0 Chunk 1, D-6/D-7).
 */
function notificationService(): NotificationService
{
    return app(NotificationService::class);
}

it('writes a notifications row for the recipient (default-resolution, no pref rows)', function (): void {
    $recipient = User::factory()->createOne();
    $actor = User::factory()->createOne();

    $notification = notificationService()->notify(
        recipient: $recipient,
        type: NotificationType::AssignmentDraftApproved,
        actor: $actor,
        data: ['campaign_name' => 'Spring Launch'],
    );

    expect($notification)->not->toBeNull()
        ->and($notification?->recipient_user_id)->toBe($recipient->id)
        ->and($notification?->actor_user_id)->toBe($actor->id)
        ->and($notification?->type)->toBe(NotificationType::AssignmentDraftApproved)
        ->and($notification?->data)->toBe(['campaign_name' => 'Spring Launch'])
        ->and($notification?->read_at)->toBeNull();

    expect(Notification::query()->where('recipient_user_id', $recipient->id)->count())->toBe(1);
});

it('persists the polymorphic subject when one is given', function (): void {
    $recipient = User::factory()->createOne();
    $subject = User::factory()->createOne();

    $notification = notificationService()->notify(
        recipient: $recipient,
        type: NotificationType::AssignmentInvited,
        subject: $subject,
    );

    expect($notification?->subject_type)->toBe($subject->getMorphClass())
        ->and($notification?->subject_id)->toBe($subject->id);
});

it('does NOT write a row when the in_app preference is disabled for the type', function (): void {
    $recipient = User::factory()->createOne();

    NotificationPreference::factory()
        ->ofType(NotificationType::AssignmentDraftApproved)
        ->channel(NotificationChannel::InApp)
        ->disabled()
        ->createOne(['user_id' => $recipient->id]);

    $notification = notificationService()->notify(
        recipient: $recipient,
        type: NotificationType::AssignmentDraftApproved,
    );

    expect($notification)->toBeNull();
    expect(Notification::query()->where('recipient_user_id', $recipient->id)->count())->toBe(0);
});

it('still writes when an UNRELATED channel is disabled (per-channel resolution)', function (): void {
    $recipient = User::factory()->createOne();

    // Email off must not suppress the in-app row.
    NotificationPreference::factory()
        ->ofType(NotificationType::AssignmentDraftApproved)
        ->channel(NotificationChannel::Email)
        ->disabled()
        ->createOne(['user_id' => $recipient->id]);

    $notification = notificationService()->notify(
        recipient: $recipient,
        type: NotificationType::AssignmentDraftApproved,
    );

    expect($notification)->not->toBeNull();
});

it('resolves preserve-current defaults: in_app on, email on, digest off', function (): void {
    $recipient = User::factory()->createOne();
    $service = notificationService();

    expect($service->isChannelEnabled($recipient, NotificationType::AssignmentDraftApproved, NotificationChannel::InApp))->toBeTrue()
        ->and($service->isChannelEnabled($recipient, NotificationType::AssignmentDraftApproved, NotificationChannel::Email))->toBeTrue()
        ->and($service->isChannelEnabled($recipient, NotificationType::AssignmentDraftApproved, NotificationChannel::Digest))->toBeFalse();
});

it('a stored preference overrides the computed default', function (): void {
    $recipient = User::factory()->createOne();
    $service = notificationService();

    NotificationPreference::factory()
        ->ofType(NotificationType::AssignmentDraftApproved)
        ->channel(NotificationChannel::Digest)
        ->createOne(['user_id' => $recipient->id, 'is_enabled' => true]);

    expect($service->isChannelEnabled($recipient, NotificationType::AssignmentDraftApproved, NotificationChannel::Digest))->toBeTrue();
});
