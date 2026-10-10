<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Models\NotificationPreference;
use App\Modules\Notifications\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * AH-102 (Q3) — the per-type default, BOTH polarities.
 *
 * `campaign.job_posted` × `push` defaults OFF (promotional push is opt-in);
 * every other push cell defaults ON. The danger this file exists for is the
 * WRITE side: if `setPreference()` compared against the channel default (ON)
 * while the read side used the type default (OFF), switching job_posted push ON
 * would be treated as "back at the default" → row deleted → read as OFF, and
 * the opt-in would silently never stick.
 */
function pushPrefService(): NotificationService
{
    return app(NotificationService::class);
}

function pushRowCount(User $user, NotificationType $type): int
{
    return NotificationPreference::query()
        ->where('user_id', $user->id)
        ->where('type', $type->value)
        ->where('channel', 'push')
        ->count();
}

// ── T1–T3: job_posted × push (default OFF) ───────────────────────────────────

it('T1 job_posted push, no row → OFF', function (): void {
    $user = User::factory()->createOne();

    expect(pushPrefService()->isChannelEnabled($user, NotificationType::CampaignJobPosted, NotificationChannel::Push))->toBeFalse();
});

it('T2 job_posted push, setPreference(true) → row STORED, reads ON', function (): void {
    $user = User::factory()->createOne();

    pushPrefService()->setPreference($user, NotificationType::CampaignJobPosted, NotificationChannel::Push, true);

    expect(pushRowCount($user, NotificationType::CampaignJobPosted))->toBe(1)
        ->and(pushPrefService()->isChannelEnabled($user, NotificationType::CampaignJobPosted, NotificationChannel::Push))->toBeTrue();
});

it('T3 job_posted push, setPreference(false) after opting in → row DELETED, reads OFF', function (): void {
    $user = User::factory()->createOne();
    pushPrefService()->setPreference($user, NotificationType::CampaignJobPosted, NotificationChannel::Push, true);

    pushPrefService()->setPreference($user, NotificationType::CampaignJobPosted, NotificationChannel::Push, false);

    expect(pushRowCount($user, NotificationType::CampaignJobPosted))->toBe(0)
        ->and(pushPrefService()->isChannelEnabled($user, NotificationType::CampaignJobPosted, NotificationChannel::Push))->toBeFalse();
});

// ── T4–T6: a control type × push (default ON) ────────────────────────────────

it('T4 control type push, no row → ON', function (): void {
    $user = User::factory()->createOne();

    expect(pushPrefService()->isChannelEnabled($user, NotificationType::AssignmentInvited, NotificationChannel::Push))->toBeTrue();
});

it('T5 control type push, setPreference(false) → row STORED, reads OFF', function (): void {
    $user = User::factory()->createOne();

    pushPrefService()->setPreference($user, NotificationType::AssignmentInvited, NotificationChannel::Push, false);

    expect(pushRowCount($user, NotificationType::AssignmentInvited))->toBe(1)
        ->and(pushPrefService()->isChannelEnabled($user, NotificationType::AssignmentInvited, NotificationChannel::Push))->toBeFalse();
});

it('T6 control type push, setPreference(true) after opting out → row DELETED, reads ON', function (): void {
    $user = User::factory()->createOne();
    pushPrefService()->setPreference($user, NotificationType::AssignmentInvited, NotificationChannel::Push, false);

    pushPrefService()->setPreference($user, NotificationType::AssignmentInvited, NotificationChannel::Push, true);

    expect(pushRowCount($user, NotificationType::AssignmentInvited))->toBe(0)
        ->and(pushPrefService()->isChannelEnabled($user, NotificationType::AssignmentInvited, NotificationChannel::Push))->toBeTrue();
});

// ── T7: the same type's OTHER channels are untouched ─────────────────────────

it('T7 job_posted in_app / email keep their channel defaults (the override is push-only)', function (): void {
    $user = User::factory()->createOne();
    $service = pushPrefService();

    expect($service->isChannelEnabled($user, NotificationType::CampaignJobPosted, NotificationChannel::InApp))->toBeTrue()
        ->and($service->isChannelEnabled($user, NotificationType::CampaignJobPosted, NotificationChannel::Email))->toBeTrue()
        ->and($service->isChannelEnabled($user, NotificationType::CampaignJobPosted, NotificationChannel::Digest))->toBeFalse();
});

// ── T8: the wire contract ────────────────────────────────────────────────────

it('T8 GET ships defaults.push = true and type_defaults with exactly the ruled exception', function (): void {
    $user = User::factory()->createOne();

    $this->actingAs($user)
        ->getJson('/api/v1/me/notification-preferences')
        ->assertOk()
        ->assertJsonPath('data.attributes.defaults.push', true)
        ->assertJsonPath('data.attributes.defaults.in_app', true)
        ->assertJsonPath('data.attributes.defaults.email', true)
        ->assertJsonPath('data.attributes.defaults.digest', false)
        ->assertJsonPath('data.attributes.type_defaults', ['campaign.job_posted' => ['push' => false]]);
});

// ── T9: the same, through HTTP ───────────────────────────────────────────────

it('T9 PATCH round-trips both polarities through the endpoint and keeps type_defaults', function (): void {
    $user = User::factory()->createOne();

    $patch = fn (string $type, bool $enabled) => $this->actingAs($user)->patchJson('/api/v1/me/notification-preferences', [
        'preferences' => [['notification_type' => $type, 'channel' => 'push', 'is_enabled' => $enabled]],
    ]);

    // job_posted: ON diverges (stored) …
    $patch('campaign.job_posted', true)
        ->assertOk()
        ->assertJsonCount(1, 'data.attributes.preferences')
        ->assertJsonPath('data.attributes.preferences.0.channel', 'push')
        ->assertJsonPath('data.attributes.preferences.0.is_enabled', true)
        ->assertJsonPath('data.attributes.type_defaults', ['campaign.job_posted' => ['push' => false]]);

    // … OFF returns to the default (deleted).
    $patch('campaign.job_posted', false)
        ->assertOk()
        ->assertJsonCount(0, 'data.attributes.preferences');

    // control: OFF diverges (stored) …
    $patch('assignment.invited', false)
        ->assertOk()
        ->assertJsonCount(1, 'data.attributes.preferences')
        ->assertJsonPath('data.attributes.preferences.0.is_enabled', false);

    // … ON returns to the default (deleted).
    $patch('assignment.invited', true)
        ->assertOk()
        ->assertJsonCount(0, 'data.attributes.preferences');

    expect(NotificationPreference::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('PATCH accepts the push channel and still rejects an unknown channel', function (): void {
    $user = User::factory()->createOne();

    $this->actingAs($user)->patchJson('/api/v1/me/notification-preferences', [
        'preferences' => [['notification_type' => 'assignment.invited', 'channel' => 'carrier_pigeon', 'is_enabled' => true]],
    ])->assertStatus(422);
});
