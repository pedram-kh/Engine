<?php

declare(strict_types=1);

use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Enums\ApplicationStatus;
use App\Modules\Creators\Mail\CreatorApprovedMail;
use App\Modules\Creators\Mail\CreatorRejectedMail;
use App\Modules\Identity\Enums\UserType;
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
 * S11.0 Chunk 2 (D-2 #10/#11) — the admin approve/reject creator-lifecycle
 * retrofit. Each emits an in-app notification (creator.approved /
 * creator.rejected — the two clean enum-adds) ALONGSIDE the untouched
 * lifecycle mail, with the acting admin as the actor and the Creator as the
 * subject. Per-type in_app preference is honoured (email never affected).
 */
function lifecycleAdmin(): User
{
    return User::factory()->create([
        'type' => UserType::PlatformAdmin,
        'two_factor_confirmed_at' => now(),
    ]);
}

it('approve emits a creator.approved in-app row alongside the untouched email', function (): void {
    Mail::fake();

    $admin = lifecycleAdmin();
    $creatorUser = User::factory()->create(['type' => UserType::Creator]);
    $creator = CreatorFactory::new()->kycVerified()->createOne([
        'user_id' => $creatorUser->id,
        'application_status' => ApplicationStatus::Pending->value,
    ]);

    $this->actingAs($admin, 'web_admin')
        ->postJson("/api/v1/admin/creators/{$creator->ulid}/approve", ['welcome_message' => 'Welcome aboard!'])
        ->assertOk();

    $row = Notification::query()
        ->where('recipient_user_id', $creatorUser->id)
        ->where('type', NotificationType::CreatorApproved->value)
        ->first();

    expect($row)->not->toBeNull()
        ->and($row?->actor_user_id)->toBe($admin->id)
        ->and($row?->subject_type)->toBe($creator->getMorphClass())
        ->and($row?->subject_id)->toBe($creator->id)
        ->and($row?->data['welcome_message'] ?? null)->toBe('Welcome aboard!');

    Mail::assertQueued(CreatorApprovedMail::class);
});

it('reject emits a creator.rejected in-app row carrying the reason, alongside the untouched email', function (): void {
    Mail::fake();

    $admin = lifecycleAdmin();
    $creatorUser = User::factory()->create(['type' => UserType::Creator]);
    $creator = CreatorFactory::new()->createOne([
        'user_id' => $creatorUser->id,
        'application_status' => ApplicationStatus::Pending->value,
    ]);

    $reason = 'Portfolio insufficient for Tier 1 review.';

    $this->actingAs($admin, 'web_admin')
        ->postJson("/api/v1/admin/creators/{$creator->ulid}/reject", ['rejection_reason' => $reason])
        ->assertOk();

    $row = Notification::query()
        ->where('recipient_user_id', $creatorUser->id)
        ->where('type', NotificationType::CreatorRejected->value)
        ->first();

    expect($row)->not->toBeNull()
        ->and($row?->actor_user_id)->toBe($admin->id)
        ->and($row?->subject_id)->toBe($creator->id)
        ->and($row?->data['rejection_reason'] ?? null)->toBe($reason);

    Mail::assertQueued(CreatorRejectedMail::class);
});

it('respects the creator in_app opt-out on reject — no row, email still queued', function (): void {
    Mail::fake();

    $admin = lifecycleAdmin();
    $creatorUser = User::factory()->create(['type' => UserType::Creator]);
    $creator = CreatorFactory::new()->createOne([
        'user_id' => $creatorUser->id,
        'application_status' => ApplicationStatus::Pending->value,
    ]);

    NotificationPreference::factory()
        ->ofType(NotificationType::CreatorRejected)
        ->channel(NotificationChannel::InApp)
        ->disabled()
        ->createOne(['user_id' => $creatorUser->id]);

    $this->actingAs($admin, 'web_admin')
        ->postJson("/api/v1/admin/creators/{$creator->ulid}/reject", ['rejection_reason' => 'Insufficient portfolio depth.'])
        ->assertOk();

    expect(Notification::query()->where('recipient_user_id', $creatorUser->id)->count())->toBe(0);
    Mail::assertQueued(CreatorRejectedMail::class);
});
