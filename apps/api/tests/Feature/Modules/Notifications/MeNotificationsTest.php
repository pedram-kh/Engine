<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * The four per-user `/me/notifications` endpoints (S11.0 Chunk 1, D-8/D-9).
 * Owner-scoped, idempotent mark-read (§5.6), unread-count correctness, and the
 * absence test (user A can never read user B's notifications).
 */

// ── feed ───────────────────────────────────────────────────────────────────

it('GET /me/notifications returns the caller\'s own feed, newest first', function (): void {
    $user = User::factory()->createOne();

    $older = Notification::factory()->createOne(['recipient_user_id' => $user->id, 'created_at' => now()->subHour()]);
    $newer = Notification::factory()->createOne(['recipient_user_id' => $user->id, 'created_at' => now()]);

    $this->actingAs($user)
        ->getJson('/api/v1/me/notifications')
        ->assertOk()
        ->assertJsonPath('data.0.id', $newer->ulid)
        ->assertJsonPath('data.1.id', $older->ulid)
        ->assertJsonPath('data.0.type', 'notifications')
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.unread_count', 2);
});

it('paginates the feed with flat meta', function (): void {
    $user = User::factory()->createOne();
    Notification::factory()->count(3)->create(['recipient_user_id' => $user->id]);

    $this->actingAs($user)
        ->getJson('/api/v1/me/notifications?per_page=2')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.last_page', 2);
});

// ── unread-count ─────────────────────────────────────────────────────────────

it('GET /me/notifications/unread-count counts only unread rows', function (): void {
    $user = User::factory()->createOne();
    Notification::factory()->count(2)->create(['recipient_user_id' => $user->id]);
    Notification::factory()->read()->createOne(['recipient_user_id' => $user->id]);

    $this->actingAs($user)
        ->getJson('/api/v1/me/notifications/unread-count')
        ->assertOk()
        ->assertJsonPath('data.attributes.unread_count', 2);
});

// ── mark-read (idempotency, §5.6) ────────────────────────────────────────────

it('PATCH /me/notifications/{ulid}/read marks an unread row read', function (): void {
    $user = User::factory()->createOne();
    $notification = Notification::factory()->createOne(['recipient_user_id' => $user->id]);

    $this->actingAs($user)
        ->patchJson("/api/v1/me/notifications/{$notification->ulid}/read")
        ->assertOk()
        ->assertJsonPath('meta.code', 'notification.read');

    expect($notification->fresh()?->read_at)->not->toBeNull();
});

it('re-marking an already-read row is a no-op (idempotent, §5.6)', function (): void {
    $user = User::factory()->createOne();
    $readAt = now()->subDay();
    $notification = Notification::factory()->createOne([
        'recipient_user_id' => $user->id,
        'read_at' => $readAt,
    ]);

    $this->actingAs($user)
        ->patchJson("/api/v1/me/notifications/{$notification->ulid}/read")
        ->assertOk();

    // The original read_at is preserved — re-marking did not bump it.
    expect($notification->fresh()?->read_at?->toIso8601String())
        ->toBe($readAt->toIso8601String());
});

// ── read-all ─────────────────────────────────────────────────────────────────

it('POST /me/notifications/read-all marks every unread row read', function (): void {
    $user = User::factory()->createOne();
    Notification::factory()->count(3)->create(['recipient_user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson('/api/v1/me/notifications/read-all')
        ->assertOk()
        ->assertJsonPath('meta.code', 'notification.read_all')
        ->assertJsonPath('data.attributes.marked_count', 3);

    expect(Notification::query()->where('recipient_user_id', $user->id)->whereNull('read_at')->count())->toBe(0);
});

it('read-all is idempotent — nothing unread marks zero and writes nothing', function (): void {
    $user = User::factory()->createOne();
    Notification::factory()->read()->count(2)->create(['recipient_user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson('/api/v1/me/notifications/read-all')
        ->assertOk()
        ->assertJsonPath('data.attributes.marked_count', 0);
});

// ── absence test (per-user isolation, D-9) ────────────────────────────────────

it('user A cannot see user B\'s notifications in the feed', function (): void {
    $userA = User::factory()->createOne();
    $userB = User::factory()->createOne();
    Notification::factory()->count(2)->create(['recipient_user_id' => $userB->id]);

    $this->actingAs($userA)
        ->getJson('/api/v1/me/notifications')
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.total', 0)
        ->assertJsonPath('meta.unread_count', 0);
});

it('user A cannot mark user B\'s notification read (404, no enumeration)', function (): void {
    $userA = User::factory()->createOne();
    $userB = User::factory()->createOne();
    $notificationB = Notification::factory()->createOne(['recipient_user_id' => $userB->id]);

    $this->actingAs($userA)
        ->patchJson("/api/v1/me/notifications/{$notificationB->ulid}/read")
        ->assertNotFound()
        ->assertJsonPath('errors.0.code', 'notification.not_found');

    // B's row is untouched.
    expect($notificationB->fresh()?->read_at)->toBeNull();
});

it('requires authentication', function (): void {
    $this->getJson('/api/v1/me/notifications')->assertUnauthorized();
});
