<?php

declare(strict_types=1);

use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Enums\BlockType;
use App\Modules\Creators\Enums\Kind;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Models\CreatorAvailabilityBlock;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Sprint 5 Chunk A — creators/me/availability CRUD + validation + enums.
 *
 * Ownership is structural (resolved from $request->user()->creator), so the
 * owner-only guard is exercised by a cross-creator write that must 404
 * (§5.35 break-revert: drop the relation-scoped resolve in
 * CreatorAvailabilityController::resolveBlock() and the cross-creator
 * update/delete tests below start succeeding).
 */

/**
 * A creator owned by a fresh user, returned with its user for acting-as.
 *
 * @return array{0: User, 1: Creator}
 */
function creatorWithUser(): array
{
    $user = User::factory()->create();
    $creator = CreatorFactory::new()->createOne(['user_id' => $user->id]);

    return [$user, $creator];
}

/**
 * @return array<string, mixed>
 */
function validBlockPayload(array $overrides = []): array
{
    return array_merge([
        'starts_at' => '2026-06-01T09:00:00+00:00',
        'ends_at' => '2026-06-01T17:00:00+00:00',
        'is_all_day' => false,
        'block_type' => 'hard',
        'kind' => 'vacation',
        'reason' => 'Family trip',
    ], $overrides);
}

// ---------------------------------------------------------------------------
// Create
// ---------------------------------------------------------------------------

it('creates a block for the authenticated creator', function (): void {
    [$user, $creator] = creatorWithUser();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/creators/me/availability', validBlockPayload());

    $response->assertCreated()
        ->assertJsonPath('data.attributes.kind', 'vacation')
        ->assertJsonPath('data.attributes.block_type', 'hard');

    $block = CreatorAvailabilityBlock::query()->where('creator_id', $creator->id)->sole();
    expect($block->kind)->toBe(Kind::Vacation)
        ->and($block->block_type)->toBe(BlockType::Hard);
});

it('returns 401 when unauthenticated', function (): void {
    expect($this->postJson('/api/v1/creators/me/availability', validBlockPayload())->status())
        ->toBe(401);
});

// ---------------------------------------------------------------------------
// Validation — ranges + enums + assignment_auto
// ---------------------------------------------------------------------------

it('rejects ends_at not after starts_at', function (): void {
    [$user] = creatorWithUser();

    $this->actingAs($user)
        ->postJson('/api/v1/creators/me/availability', validBlockPayload([
            'starts_at' => '2026-06-01T17:00:00+00:00',
            'ends_at' => '2026-06-01T09:00:00+00:00',
        ]))
        ->assertStatus(422)
        ->assertJsonPath('errors.0.source.pointer', '/data/attributes/ends_at');
});

it('rejects an unknown block_type (enum cast guard)', function (): void {
    [$user] = creatorWithUser();

    $this->actingAs($user)
        ->postJson('/api/v1/creators/me/availability', validBlockPayload(['block_type' => 'medium']))
        ->assertStatus(422)
        ->assertJsonPath('errors.0.source.pointer', '/data/attributes/block_type');
});

it('rejects an unknown kind', function (): void {
    [$user] = creatorWithUser();

    $this->actingAs($user)
        ->postJson('/api/v1/creators/me/availability', validBlockPayload(['kind' => 'nonsense']))
        ->assertStatus(422)
        ->assertJsonPath('errors.0.source.pointer', '/data/attributes/kind');
});

it('forbids a creator from setting kind=assignment_auto (system-reserved, D-a2)', function (): void {
    [$user, $creator] = creatorWithUser();

    $this->actingAs($user)
        ->postJson('/api/v1/creators/me/availability', validBlockPayload(['kind' => 'assignment_auto']))
        ->assertStatus(422)
        ->assertJsonPath('errors.0.source.pointer', '/data/attributes/kind');

    expect(CreatorAvailabilityBlock::query()->where('creator_id', $creator->id)->count())->toBe(0);
});

it('confirms assignment_auto is excluded from the creator-settable kinds', function (): void {
    expect(Kind::creatorSettable())
        ->not->toContain('assignment_auto')
        ->toContain('vacation')
        ->toContain('personal')
        ->toContain('exclusive_contract')
        ->toContain('other');
});

// ---------------------------------------------------------------------------
// Update — owner-only (break-revert anchor)
// ---------------------------------------------------------------------------

it('updates the creator own block', function (): void {
    [$user, $creator] = creatorWithUser();
    $block = CreatorAvailabilityBlock::factory()->for($creator)->create(['kind' => Kind::Vacation]);

    $this->actingAs($user)
        ->patchJson("/api/v1/creators/me/availability/{$block->ulid}", validBlockPayload(['kind' => 'personal']))
        ->assertOk()
        ->assertJsonPath('data.attributes.kind', 'personal');

    expect($block->refresh()->kind)->toBe(Kind::Personal);
});

it('returns 404 when updating another creator block (owner-only)', function (): void {
    [$user] = creatorWithUser();
    $otherCreator = CreatorFactory::new()->createOne();
    $foreignBlock = CreatorAvailabilityBlock::factory()->for($otherCreator)->create(['kind' => Kind::Vacation]);

    $this->actingAs($user)
        ->patchJson("/api/v1/creators/me/availability/{$foreignBlock->ulid}", validBlockPayload(['kind' => 'personal']))
        ->assertStatus(404);

    // The foreign block is untouched.
    expect($foreignBlock->refresh()->kind)->toBe(Kind::Vacation);
});

// ---------------------------------------------------------------------------
// Delete — owner-only (break-revert anchor)
// ---------------------------------------------------------------------------

it('deletes the creator own block', function (): void {
    [$user, $creator] = creatorWithUser();
    $block = CreatorAvailabilityBlock::factory()->for($creator)->create();

    $this->actingAs($user)
        ->deleteJson("/api/v1/creators/me/availability/{$block->ulid}")
        ->assertNoContent();

    expect(CreatorAvailabilityBlock::query()->whereKey($block->id)->exists())->toBeFalse();
});

it('returns 404 when deleting another creator block (owner-only)', function (): void {
    [$user] = creatorWithUser();
    $otherCreator = CreatorFactory::new()->createOne();
    $foreignBlock = CreatorAvailabilityBlock::factory()->for($otherCreator)->create();

    $this->actingAs($user)
        ->deleteJson("/api/v1/creators/me/availability/{$foreignBlock->ulid}")
        ->assertStatus(404);

    expect(CreatorAvailabilityBlock::query()->whereKey($foreignBlock->id)->exists())->toBeTrue();
});

// ---------------------------------------------------------------------------
// List
// ---------------------------------------------------------------------------

it('lists only the authenticated creator blocks for the window', function (): void {
    [$user, $creator] = creatorWithUser();
    $otherCreator = CreatorFactory::new()->createOne();

    CreatorAvailabilityBlock::factory()->for($creator)->create([
        'starts_at' => '2026-06-10T09:00:00+00:00',
        'ends_at' => '2026-06-10T17:00:00+00:00',
        'is_recurring' => false,
    ]);
    CreatorAvailabilityBlock::factory()->for($otherCreator)->create([
        'starts_at' => '2026-06-10T09:00:00+00:00',
        'ends_at' => '2026-06-10T17:00:00+00:00',
        'is_recurring' => false,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/creators/me/availability?from=2026-06-01T00:00:00%2B00:00&to=2026-06-30T00:00:00%2B00:00');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
});
