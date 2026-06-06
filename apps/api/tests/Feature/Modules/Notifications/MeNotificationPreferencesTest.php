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
 * The per-user notification-preference endpoints (S11.0 Chunk 3b). The sparse
 * write contract (D-1: diverge → materialize, return-to-default → delete), the
 * read shape (D-3: sparse rows + defaults block), and the owner-scope absence
 * anchor (D-7 / the Ch1 isolation contract).
 */
function service(): NotificationService
{
    return app(NotificationService::class);
}

// ── read (sparse rows + defaults) ────────────────────────────────────────────

it('GET /me/notification-preferences returns an empty set + the defaults block when no rows exist', function (): void {
    $user = User::factory()->createOne();

    $this->actingAs($user)
        ->getJson('/api/v1/me/notification-preferences')
        ->assertOk()
        ->assertJsonPath('data.type', 'notification_preferences')
        ->assertJsonCount(0, 'data.attributes.preferences')
        ->assertJsonPath('data.attributes.defaults.in_app', true)
        ->assertJsonPath('data.attributes.defaults.email', true)
        ->assertJsonPath('data.attributes.defaults.digest', false);
});

it('GET returns only the caller\'s sparse (divergent) rows', function (): void {
    $user = User::factory()->createOne();

    NotificationPreference::factory()
        ->ofType(NotificationType::AssignmentDraftApproved)
        ->channel(NotificationChannel::InApp)
        ->disabled()
        ->createOne(['user_id' => $user->id]);

    $this->actingAs($user)
        ->getJson('/api/v1/me/notification-preferences')
        ->assertOk()
        ->assertJsonCount(1, 'data.attributes.preferences')
        ->assertJsonPath('data.attributes.preferences.0.notification_type', 'assignment.draft_approved')
        ->assertJsonPath('data.attributes.preferences.0.channel', 'in_app')
        ->assertJsonPath('data.attributes.preferences.0.is_enabled', false);
});

// ── write — sparse upsert (divergence) ───────────────────────────────────────

it('PATCH materializes a row only when the toggle diverges from the default', function (): void {
    $user = User::factory()->createOne();

    // in_app defaults ON → toggling it OFF is a divergence → a row is written.
    $this->actingAs($user)
        ->patchJson('/api/v1/me/notification-preferences', [
            'preferences' => [
                ['notification_type' => 'assignment.draft_approved', 'channel' => 'in_app', 'is_enabled' => false],
            ],
        ])
        ->assertOk()
        ->assertJsonCount(1, 'data.attributes.preferences')
        ->assertJsonPath('data.attributes.preferences.0.is_enabled', false);

    expect(NotificationPreference::query()->where('user_id', $user->id)->count())->toBe(1);
    expect(service()->isChannelEnabled($user, NotificationType::AssignmentDraftApproved, NotificationChannel::InApp))->toBeFalse();
});

// ── write — return-to-default DELETE (the contract) ──────────────────────────

it('PATCH DELETES the row when a toggle returns to its default → isChannelEnabled falls back to defaultEnabled()', function (): void {
    $user = User::factory()->createOne();

    // Seed a divergence (in_app OFF).
    NotificationPreference::factory()
        ->ofType(NotificationType::AssignmentDraftApproved)
        ->channel(NotificationChannel::InApp)
        ->disabled()
        ->createOne(['user_id' => $user->id]);

    // Toggle back ON — the in_app default — must DELETE the row, not store true.
    $this->actingAs($user)
        ->patchJson('/api/v1/me/notification-preferences', [
            'preferences' => [
                ['notification_type' => 'assignment.draft_approved', 'channel' => 'in_app', 'is_enabled' => true],
            ],
        ])
        ->assertOk()
        ->assertJsonCount(0, 'data.attributes.preferences');

    // Zero rows — the preserve-current contract holds purely from the default.
    expect(NotificationPreference::query()->where('user_id', $user->id)->count())->toBe(0);
    expect(service()->isChannelEnabled($user, NotificationType::AssignmentDraftApproved, NotificationChannel::InApp))->toBeTrue();
});

it('a batch upserts divergences and deletes returns-to-default in one call', function (): void {
    $user = User::factory()->createOne();

    NotificationPreference::factory()
        ->ofType(NotificationType::CreatorApproved)
        ->channel(NotificationChannel::InApp)
        ->disabled()
        ->createOne(['user_id' => $user->id]);

    $this->actingAs($user)
        ->patchJson('/api/v1/me/notification-preferences', [
            'preferences' => [
                // diverge — write
                ['notification_type' => 'assignment.draft_approved', 'channel' => 'in_app', 'is_enabled' => false],
                // return-to-default — delete the seeded row
                ['notification_type' => 'creator.approved', 'channel' => 'in_app', 'is_enabled' => true],
            ],
        ])
        ->assertOk();

    $rows = NotificationPreference::query()->where('user_id', $user->id)->get();
    expect($rows)->toHaveCount(1)
        ->and($rows->first()?->type)->toBe(NotificationType::AssignmentDraftApproved)
        ->and($rows->first()?->is_enabled)->toBeFalse();
});

it('re-writing the same divergence does not duplicate (unique-backed upsert)', function (): void {
    $user = User::factory()->createOne();

    $body = [
        'preferences' => [
            ['notification_type' => 'assignment.draft_approved', 'channel' => 'in_app', 'is_enabled' => false],
        ],
    ];

    $this->actingAs($user)->patchJson('/api/v1/me/notification-preferences', $body)->assertOk();
    $this->actingAs($user)->patchJson('/api/v1/me/notification-preferences', $body)->assertOk();

    expect(NotificationPreference::query()->where('user_id', $user->id)->count())->toBe(1);
});

// ── owner-scope absence (the Ch1 isolation anchor) ───────────────────────────

it('a caller\'s read returns only their own rows, never another user\'s', function (): void {
    $userA = User::factory()->createOne();
    $userB = User::factory()->createOne();

    NotificationPreference::factory()
        ->ofType(NotificationType::AssignmentDraftApproved)
        ->channel(NotificationChannel::InApp)
        ->disabled()
        ->createOne(['user_id' => $userB->id]);

    $this->actingAs($userA)
        ->getJson('/api/v1/me/notification-preferences')
        ->assertOk()
        ->assertJsonCount(0, 'data.attributes.preferences');
});

it('a PATCH only ever writes the caller\'s rows — B\'s existing prefs are left untouched', function (): void {
    $userA = User::factory()->createOne();
    $userB = User::factory()->createOne();

    // B already holds a divergent pref of their OWN (draft_approved in_app OFF).
    $bRow = NotificationPreference::factory()
        ->ofType(NotificationType::AssignmentDraftApproved)
        ->channel(NotificationChannel::InApp)
        ->disabled()
        ->createOne(['user_id' => $userB->id]);

    // A writes the SAME (type, channel) — there is no {user} segment, so the
    // owner is resolved structurally from $request->user(); A cannot address B.
    $this->actingAs($userA)
        ->patchJson('/api/v1/me/notification-preferences', [
            'preferences' => [
                ['notification_type' => 'assignment.draft_approved', 'channel' => 'in_app', 'is_enabled' => false],
            ],
        ])
        ->assertOk();

    // A got their own row; B's pre-existing row is byte-for-byte untouched.
    expect(NotificationPreference::query()->where('user_id', $userA->id)->count())->toBe(1);
    expect(NotificationPreference::query()->where('user_id', $userB->id)->count())->toBe(1);

    $bFresh = $bRow->fresh();
    expect($bFresh)->not->toBeNull()
        ->and($bFresh?->id)->toBe($bRow->id)
        ->and($bFresh?->is_enabled)->toBeFalse()
        ->and($bFresh?->updated_at?->equalTo($bRow->updated_at))->toBeTrue();
});

// ── validation + auth ────────────────────────────────────────────────────────

it('rejects an unknown notification type', function (): void {
    $user = User::factory()->createOne();

    $this->actingAs($user)
        ->patchJson('/api/v1/me/notification-preferences', [
            'preferences' => [
                ['notification_type' => 'totally.made.up', 'channel' => 'in_app', 'is_enabled' => false],
            ],
        ])
        ->assertStatus(422);
});

it('rejects an unknown channel', function (): void {
    $user = User::factory()->createOne();

    $this->actingAs($user)
        ->patchJson('/api/v1/me/notification-preferences', [
            'preferences' => [
                ['notification_type' => 'assignment.draft_approved', 'channel' => 'carrier_pigeon', 'is_enabled' => false],
            ],
        ])
        ->assertStatus(422);
});

it('requires authentication for both read and write', function (): void {
    $this->getJson('/api/v1/me/notification-preferences')->assertUnauthorized();
    $this->patchJson('/api/v1/me/notification-preferences', ['preferences' => []])->assertUnauthorized();
});
