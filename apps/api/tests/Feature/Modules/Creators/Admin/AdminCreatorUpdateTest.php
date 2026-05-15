<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Enums\ApplicationStatus;
use App\Modules\Creators\Policies\CreatorPolicy;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Sprint 3 Chunk 4 sub-steps 1 + 2 — admin per-field PATCH + approve / reject.
 *
 *   PATCH /api/v1/admin/creators/{creator}
 *   POST  /api/v1/admin/creators/{creator}/approve
 *   POST  /api/v1/admin/creators/{creator}/reject
 *
 * Tests the HTTP boundary (auth + policy + validation), the per-field
 * idempotency contract (#6), the audit-emission contract (#5), and the
 * separation between generic PATCH and the status-transition workflow
 * (Q-chunk-4-2 = (a) — application_status is refused with a structured
 * error code).
 *
 * Cross-layer contract verification per Sprint 3 § b: rule parity with
 * `UpdateProfileRequest` is sourced by inspection; this file pins the
 * boundary tests but the rule-parity invariants live in
 * `AdminUpdateCreatorRequestRuleParityTest`.
 */
function makePlatformAdmin(): User
{
    return User::factory()->create([
        'type' => UserType::PlatformAdmin,
        'two_factor_confirmed_at' => now(),
    ]);
}

// ---------------------------------------------------------------------------
// Auth + policy + route boundary
// ---------------------------------------------------------------------------

it('returns 401 when no admin is authenticated on PATCH', function (): void {
    $creator = CreatorFactory::new()->createOne();

    $response = $this->patchJson("/api/v1/admin/creators/{$creator->ulid}", [
        'display_name' => 'New name',
    ]);

    expect($response->status())->toBe(401);
});

it('returns 401 when a creator user authenticates on the wrong guard', function (): void {
    $creator = CreatorFactory::new()->createOne();
    $creatorUser = User::factory()->create(['type' => UserType::Creator]);

    $response = $this->actingAs($creatorUser, 'web')
        ->patchJson("/api/v1/admin/creators/{$creator->ulid}", [
            'display_name' => 'New name',
        ]);

    expect($response->status())->toBe(401);
});

it('returns 403 when an MFA-confirmed non-admin user authenticates as web_admin (defensive)', function (): void {
    $creator = CreatorFactory::new()->createOne();
    // This user is not platform_admin — adminUpdate policy denies.
    $otherUser = User::factory()->create([
        'type' => UserType::Creator,
        'two_factor_confirmed_at' => now(),
    ]);

    $response = $this->actingAs($otherUser, 'web_admin')
        ->patchJson("/api/v1/admin/creators/{$creator->ulid}", [
            'display_name' => 'New name',
        ]);

    expect($response->status())->toBe(403);
});

it('returns 404 when the creator ulid does not match a row', function (): void {
    $admin = makePlatformAdmin();

    $response = $this->actingAs($admin, 'web_admin')
        ->patchJson('/api/v1/admin/creators/01H00000000000000000000000', [
            'display_name' => 'New name',
        ]);

    expect($response->status())->toBe(404);
});

// ---------------------------------------------------------------------------
// Per-field PATCH happy path + audit emission
// ---------------------------------------------------------------------------

it('updates display_name and emits creator.admin.field_updated audit (no reason required)', function (): void {
    $admin = makePlatformAdmin();
    $creator = CreatorFactory::new()->createOne([
        'display_name' => 'Original Name',
    ]);

    $response = $this->actingAs($admin, 'web_admin')
        ->patchJson("/api/v1/admin/creators/{$creator->ulid}", [
            'display_name' => 'Updated Name',
        ]);

    expect($response->status())->toBe(200);
    expect($response->json('data.attributes.display_name'))->toBe('Updated Name');

    $audit = AuditLog::query()
        ->where('action', AuditAction::CreatorAdminFieldUpdated->value)
        ->latest('id')
        ->first();
    expect($audit)->not->toBeNull();
    assert($audit !== null);
    expect($audit->actor_id)->toBe($admin->id);
    /** @var array<string, mixed> $metadata */
    $metadata = $audit->metadata;
    expect($metadata['field'])->toBe('display_name');
    expect($metadata['before'])->toBe('Original Name');
    expect($metadata['after'])->toBe('Updated Name');
});

it('updates bio when a reason is provided', function (): void {
    $admin = makePlatformAdmin();
    $creator = CreatorFactory::new()->createOne(['bio' => 'Old bio.']);

    $response = $this->actingAs($admin, 'web_admin')
        ->patchJson("/api/v1/admin/creators/{$creator->ulid}", [
            'bio' => 'New bio with markdown.',
            'reason' => 'PII redaction request from creator.',
        ]);

    expect($response->status())->toBe(200);
    expect($response->json('data.attributes.bio'))->toBe('New bio with markdown.');

    $audit = AuditLog::query()
        ->where('action', AuditAction::CreatorAdminFieldUpdated->value)
        ->latest('id')
        ->first();
    expect($audit)->not->toBeNull();
    assert($audit !== null);
    /** @var array<string, mixed> $metadata */
    $metadata = $audit->metadata;
    expect($metadata['reason'])->toBe('PII redaction request from creator.');
});

it('updates categories when a reason is provided', function (): void {
    $admin = makePlatformAdmin();
    $creator = CreatorFactory::new()->createOne([
        'categories' => ['fashion', 'beauty'],
    ]);

    $response = $this->actingAs($admin, 'web_admin')
        ->patchJson("/api/v1/admin/creators/{$creator->ulid}", [
            'categories' => ['fashion', 'beauty', 'travel'],
            'reason' => 'Add travel — creator submitted new portfolio.',
        ]);

    expect($response->status())->toBe(200);
    expect($response->json('data.attributes.categories'))
        ->toEqualCanonicalizing(['fashion', 'beauty', 'travel']);
});

it('refuses bio update without a reason — 422 with reason error', function (): void {
    $admin = makePlatformAdmin();
    $creator = CreatorFactory::new()->createOne(['bio' => 'Old bio.']);

    $response = $this->actingAs($admin, 'web_admin')
        ->patchJson("/api/v1/admin/creators/{$creator->ulid}", [
            'bio' => 'New bio without reason.',
        ]);

    expect($response->status())->toBe(422);
    $fresh = $creator->fresh();
    assert($fresh !== null);
    expect($fresh->bio)->toBe('Old bio.');
});

it('refuses categories update without a reason — 422 with reason error', function (): void {
    $admin = makePlatformAdmin();
    $creator = CreatorFactory::new()->createOne([
        'categories' => ['fashion'],
    ]);

    $response = $this->actingAs($admin, 'web_admin')
        ->patchJson("/api/v1/admin/creators/{$creator->ulid}", [
            'categories' => ['fashion', 'beauty'],
        ]);

    expect($response->status())->toBe(422);
});

// ---------------------------------------------------------------------------
// Idempotency (#6)
// ---------------------------------------------------------------------------

it('same-value updates are no-ops: no audit emitted, updated_at not bumped', function (): void {
    $admin = makePlatformAdmin();
    $creator = CreatorFactory::new()->createOne(['display_name' => 'Same']);
    $freshBefore = $creator->fresh();
    assert($freshBefore !== null);
    $originalUpdatedAt = $freshBefore->updated_at;

    sleep(1);

    $response = $this->actingAs($admin, 'web_admin')
        ->patchJson("/api/v1/admin/creators/{$creator->ulid}", [
            'display_name' => 'Same',
        ]);

    expect($response->status())->toBe(200);

    $auditCount = AuditLog::query()
        ->where('action', AuditAction::CreatorAdminFieldUpdated->value)
        ->count();
    expect($auditCount)->toBe(0);

    $freshAfter = $creator->fresh();
    assert($freshAfter !== null);
    expect($freshAfter->updated_at->equalTo($originalUpdatedAt))->toBeTrue();
});

it('same-value array-shaped updates are idempotent (order-insensitive)', function (): void {
    $admin = makePlatformAdmin();
    $creator = CreatorFactory::new()->createOne([
        'categories' => ['fashion', 'beauty'],
    ]);

    // Submit the same set in different order — should be a no-op.
    $response = $this->actingAs($admin, 'web_admin')
        ->patchJson("/api/v1/admin/creators/{$creator->ulid}", [
            'categories' => ['beauty', 'fashion'],
            'reason' => 'No-op test.',
        ]);

    expect($response->status())->toBe(200);

    $auditCount = AuditLog::query()
        ->where('action', AuditAction::CreatorAdminFieldUpdated->value)
        ->count();
    expect($auditCount)->toBe(0);
});

// ---------------------------------------------------------------------------
// application_status separation (Q-chunk-4-2 = (a))
// ---------------------------------------------------------------------------

it('refuses application_status in generic PATCH with creator.admin.field_status_immutable code', function (): void {
    $admin = makePlatformAdmin();
    $creator = CreatorFactory::new()->createOne([
        'application_status' => ApplicationStatus::Pending->value,
    ]);

    $response = $this->actingAs($admin, 'web_admin')
        ->patchJson("/api/v1/admin/creators/{$creator->ulid}", [
            'application_status' => 'approved',
        ]);

    expect($response->status())->toBe(422);
    expect($response->json('errors.0.code'))->toBe('creator.admin.field_status_immutable');
    $fresh = $creator->fresh();
    assert($fresh !== null);
    expect($fresh->application_status)->toBe(ApplicationStatus::Pending);
});

it('refuses zero-field PATCH with a generic field-required error', function (): void {
    $admin = makePlatformAdmin();
    $creator = CreatorFactory::new()->createOne();

    $response = $this->actingAs($admin, 'web_admin')
        ->patchJson("/api/v1/admin/creators/{$creator->ulid}", []);

    expect($response->status())->toBe(422);
});

it('refuses multi-field PATCH per Decision E1=a (one field per request)', function (): void {
    $admin = makePlatformAdmin();
    $creator = CreatorFactory::new()->createOne();

    $response = $this->actingAs($admin, 'web_admin')
        ->patchJson("/api/v1/admin/creators/{$creator->ulid}", [
            'display_name' => 'New',
            'region' => 'New Region',
        ]);

    expect($response->status())->toBe(422);
});

// ---------------------------------------------------------------------------
// Cross-layer contract: rule parity with the wizard form-request
// ---------------------------------------------------------------------------

it('rejects display_name longer than 120 chars (matches wizard cap)', function (): void {
    $admin = makePlatformAdmin();
    $creator = CreatorFactory::new()->createOne();

    $response = $this->actingAs($admin, 'web_admin')
        ->patchJson("/api/v1/admin/creators/{$creator->ulid}", [
            'display_name' => str_repeat('a', 121),
        ]);

    expect($response->status())->toBe(422);
});

it('rejects categories outside the 16-enum set (matches wizard enum)', function (): void {
    $admin = makePlatformAdmin();
    $creator = CreatorFactory::new()->createOne();

    $response = $this->actingAs($admin, 'web_admin')
        ->patchJson("/api/v1/admin/creators/{$creator->ulid}", [
            'categories' => ['not_a_valid_category'],
            'reason' => 'Test.',
        ]);

    expect($response->status())->toBe(422);
});

it('rejects categories array with > 8 entries (matches wizard cap)', function (): void {
    $admin = makePlatformAdmin();
    $creator = CreatorFactory::new()->createOne();

    $response = $this->actingAs($admin, 'web_admin')
        ->patchJson("/api/v1/admin/creators/{$creator->ulid}", [
            'categories' => ['lifestyle', 'sports', 'beauty', 'fashion', 'food', 'travel', 'gaming', 'tech', 'music'],
            'reason' => 'Test.',
        ]);

    expect($response->status())->toBe(422);
});

it('rejects bio longer than 5000 chars (matches wizard cap)', function (): void {
    $admin = makePlatformAdmin();
    $creator = CreatorFactory::new()->createOne();

    $response = $this->actingAs($admin, 'web_admin')
        ->patchJson("/api/v1/admin/creators/{$creator->ulid}", [
            'bio' => str_repeat('a', 5001),
            'reason' => 'Test.',
        ]);

    expect($response->status())->toBe(422);
});

// ---------------------------------------------------------------------------
// Approve / reject endpoints
// ---------------------------------------------------------------------------

it('approves a pending creator and emits creator.approved audit', function (): void {
    $admin = makePlatformAdmin();
    $creator = CreatorFactory::new()->createOne([
        'application_status' => ApplicationStatus::Pending->value,
    ]);

    $response = $this->actingAs($admin, 'web_admin')
        ->postJson("/api/v1/admin/creators/{$creator->ulid}/approve", [
            'welcome_message' => 'Welcome to the platform!',
        ]);

    expect($response->status())->toBe(200);
    expect($response->json('data.attributes.application_status'))->toBe('approved');

    $fresh = $creator->fresh();
    assert($fresh !== null);
    expect($fresh->application_status)->toBe(ApplicationStatus::Approved);
    expect($fresh->approved_at)->not->toBeNull();
    expect($fresh->approved_by_user_id)->toBe($admin->id);
    expect($fresh->welcome_message)->toBe('Welcome to the platform!');

    $audit = AuditLog::query()
        ->where('action', AuditAction::CreatorApproved->value)
        ->latest('id')
        ->first();
    expect($audit)->not->toBeNull();
    assert($audit !== null);
    /** @var array<string, mixed> $metadata */
    $metadata = $audit->metadata;
    expect($metadata['welcome_message'])->toBe('Welcome to the platform!');
});

it('approves without a welcome message (optional field)', function (): void {
    $admin = makePlatformAdmin();
    $creator = CreatorFactory::new()->createOne([
        'application_status' => ApplicationStatus::Pending->value,
    ]);

    $response = $this->actingAs($admin, 'web_admin')
        ->postJson("/api/v1/admin/creators/{$creator->ulid}/approve", []);

    expect($response->status())->toBe(200);
    $fresh = $creator->fresh();
    assert($fresh !== null);
    expect($fresh->welcome_message)->toBeNull();
});

it('returns 409 + creator.already_approved when approving an already-approved creator (idempotent)', function (): void {
    $admin = makePlatformAdmin();
    $creator = CreatorFactory::new()->createOne([
        'application_status' => ApplicationStatus::Approved->value,
        'approved_at' => now()->subDay(),
    ]);
    $freshBefore = $creator->fresh();
    assert($freshBefore !== null);
    $originalApprovedAt = $freshBefore->approved_at;
    assert($originalApprovedAt !== null);

    $response = $this->actingAs($admin, 'web_admin')
        ->postJson("/api/v1/admin/creators/{$creator->ulid}/approve");

    expect($response->status())->toBe(409);
    expect($response->json('errors.0.code'))->toBe('creator.already_approved');
    $freshAfter = $creator->fresh();
    assert($freshAfter !== null);
    assert($freshAfter->approved_at !== null);
    expect($freshAfter->approved_at->equalTo($originalApprovedAt))->toBeTrue();
});

it('rejects a pending creator with a rejection_reason and emits creator.rejected audit', function (): void {
    $admin = makePlatformAdmin();
    $creator = CreatorFactory::new()->createOne([
        'application_status' => ApplicationStatus::Pending->value,
    ]);

    $response = $this->actingAs($admin, 'web_admin')
        ->postJson("/api/v1/admin/creators/{$creator->ulid}/reject", [
            'rejection_reason' => 'Portfolio insufficient for Tier 1 review.',
        ]);

    expect($response->status())->toBe(200);
    expect($response->json('data.attributes.application_status'))->toBe('rejected');

    $fresh = $creator->fresh();
    assert($fresh !== null);
    expect($fresh->application_status)->toBe(ApplicationStatus::Rejected);
    expect($fresh->rejected_at)->not->toBeNull();
    expect($fresh->rejection_reason)->toBe('Portfolio insufficient for Tier 1 review.');

    $audit = AuditLog::query()
        ->where('action', AuditAction::CreatorRejected->value)
        ->latest('id')
        ->first();
    expect($audit)->not->toBeNull();
    assert($audit !== null);
    /** @var array<string, mixed> $metadata */
    $metadata = $audit->metadata;
    expect($metadata['rejection_reason'])->toBe('Portfolio insufficient for Tier 1 review.');
});

it('rejects without rejection_reason returns 422', function (): void {
    $admin = makePlatformAdmin();
    $creator = CreatorFactory::new()->createOne([
        'application_status' => ApplicationStatus::Pending->value,
    ]);

    $response = $this->actingAs($admin, 'web_admin')
        ->postJson("/api/v1/admin/creators/{$creator->ulid}/reject", []);

    expect($response->status())->toBe(422);
});

it('rejects with too-short rejection_reason (< 10 chars) returns 422', function (): void {
    $admin = makePlatformAdmin();
    $creator = CreatorFactory::new()->createOne([
        'application_status' => ApplicationStatus::Pending->value,
    ]);

    $response = $this->actingAs($admin, 'web_admin')
        ->postJson("/api/v1/admin/creators/{$creator->ulid}/reject", [
            'rejection_reason' => 'too short',
        ]);

    expect($response->status())->toBe(422);
});

it('returns 409 + creator.already_rejected when rejecting an already-rejected creator', function (): void {
    $admin = makePlatformAdmin();
    $creator = CreatorFactory::new()->createOne([
        'application_status' => ApplicationStatus::Rejected->value,
        'rejected_at' => now()->subDay(),
        'rejection_reason' => 'Original reason for rejection here.',
    ]);

    $response = $this->actingAs($admin, 'web_admin')
        ->postJson("/api/v1/admin/creators/{$creator->ulid}/reject", [
            'rejection_reason' => 'Different reason this time around.',
        ]);

    expect($response->status())->toBe(409);
    expect($response->json('errors.0.code'))->toBe('creator.already_rejected');
});

it('returns 401 on approve when no admin is authenticated', function (): void {
    $creator = CreatorFactory::new()->createOne([
        'application_status' => ApplicationStatus::Pending->value,
    ]);

    $response = $this->postJson("/api/v1/admin/creators/{$creator->ulid}/approve");

    expect($response->status())->toBe(401);
});

it('returns 403 on approve when authenticated user is not platform_admin', function (): void {
    $creator = CreatorFactory::new()->createOne([
        'application_status' => ApplicationStatus::Pending->value,
    ]);
    $otherUser = User::factory()->create([
        'type' => UserType::Creator,
        'two_factor_confirmed_at' => now(),
    ]);

    $response = $this->actingAs($otherUser, 'web_admin')
        ->postJson("/api/v1/admin/creators/{$creator->ulid}/approve");

    expect($response->status())->toBe(403);
});

it('returns 403 on reject when authenticated user is not platform_admin', function (): void {
    $creator = CreatorFactory::new()->createOne([
        'application_status' => ApplicationStatus::Pending->value,
    ]);
    $otherUser = User::factory()->create([
        'type' => UserType::Creator,
        'two_factor_confirmed_at' => now(),
    ]);

    $response = $this->actingAs($otherUser, 'web_admin')
        ->postJson("/api/v1/admin/creators/{$creator->ulid}/reject", [
            'rejection_reason' => 'Test rejection reason here.',
        ]);

    expect($response->status())->toBe(403);
});

// ---------------------------------------------------------------------------
// CreatorPolicy::approve + ::reject — direct policy gate unit checks
// (these complement the HTTP boundary tests above)
// ---------------------------------------------------------------------------

it('CreatorPolicy::approve allows platform_admin and denies others', function (): void {
    $policy = new CreatorPolicy;
    $creator = CreatorFactory::new()->createOne();
    $admin = User::factory()->create(['type' => UserType::PlatformAdmin]);
    $agencyUser = User::factory()->create(['type' => UserType::AgencyUser]);
    $creatorUser = User::factory()->create(['type' => UserType::Creator]);

    expect($policy->approve($admin, $creator))->toBeTrue();
    expect($policy->approve($agencyUser, $creator))->toBeFalse();
    expect($policy->approve($creatorUser, $creator))->toBeFalse();
});

it('CreatorPolicy::reject allows platform_admin and denies others', function (): void {
    $policy = new CreatorPolicy;
    $creator = CreatorFactory::new()->createOne();
    $admin = User::factory()->create(['type' => UserType::PlatformAdmin]);
    $agencyUser = User::factory()->create(['type' => UserType::AgencyUser]);
    $creatorUser = User::factory()->create(['type' => UserType::Creator]);

    expect($policy->reject($admin, $creator))->toBeTrue();
    expect($policy->reject($agencyUser, $creator))->toBeFalse();
    expect($policy->reject($creatorUser, $creator))->toBeFalse();
});
