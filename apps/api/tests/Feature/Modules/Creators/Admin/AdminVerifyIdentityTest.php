<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Enums\KycMethod;
use App\Modules\Creators\Enums\KycStatus;
use App\Modules\Creators\Policies\CreatorPolicy;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Sprint 4 Chunk 3 — Cluster 1: manual KYC verify (D-c3-3).
 *
 *   POST /api/v1/admin/creators/{creator}/verify-identity
 *
 * The manual-verify endpoint is the live identity-clearing action. It is
 * compliance-sensitive: attribution (verified_by_user_id) + the
 * kyc_method=manual discriminator + the audit actor are load-bearing,
 * not optional (spot-check anchor, break-revert).
 */
function makeVerifyAdmin(): User
{
    return User::factory()->create([
        'type' => UserType::PlatformAdmin,
        'two_factor_confirmed_at' => now(),
    ]);
}

it('manually verifies identity: sets all four fields + kyc_method=manual + audit actor', function (): void {
    $admin = makeVerifyAdmin();
    $creator = CreatorFactory::new()->createOne([
        'kyc_status' => KycStatus::None->value,
    ]);

    $response = $this->actingAs($admin, 'web_admin')
        ->postJson("/api/v1/admin/creators/{$creator->ulid}/verify-identity", [
            'note' => 'Verified passport out-of-band on a support call.',
        ]);

    expect($response->status())->toBe(200);

    $fresh = $creator->fresh();
    assert($fresh !== null);
    expect($fresh->kyc_status)->toBe(KycStatus::Verified);
    expect($fresh->kyc_verified_at)->not->toBeNull();
    // Break-revert: drop kyc_method from the forceFill → this fails.
    expect($fresh->kyc_method)->toBe(KycMethod::Manual);
    // Break-revert: drop verified_by_user_id from the forceFill → this fails.
    expect($fresh->verified_by_user_id)->toBe($admin->id);

    $audit = AuditLog::query()
        ->where('action', AuditAction::CreatorKycManuallyVerified->value)
        ->latest('id')
        ->first();
    expect($audit)->not->toBeNull();
    assert($audit !== null);
    // Audit actor attribution is load-bearing for the override.
    expect($audit->actor_id)->toBe($admin->id);
    /** @var array<string, mixed> $metadata */
    $metadata = $audit->metadata;
    expect($metadata['note'])->toBe('Verified passport out-of-band on a support call.');
});

it('verifies without a note (optional field)', function (): void {
    $admin = makeVerifyAdmin();
    $creator = CreatorFactory::new()->createOne(['kyc_status' => KycStatus::None->value]);

    $response = $this->actingAs($admin, 'web_admin')
        ->postJson("/api/v1/admin/creators/{$creator->ulid}/verify-identity", []);

    expect($response->status())->toBe(200);
    $fresh = $creator->fresh();
    assert($fresh !== null);
    expect($fresh->kyc_status)->toBe(KycStatus::Verified);
    expect($fresh->kyc_method)->toBe(KycMethod::Manual);
});

it('returns 409 + creator.kyc_already_verified when identity is already verified (idempotent)', function (): void {
    $admin = makeVerifyAdmin();
    $verifiedAt = now()->subDay();
    $creator = CreatorFactory::new()->createOne([
        'kyc_status' => KycStatus::Verified->value,
        'kyc_verified_at' => $verifiedAt,
        'kyc_method' => KycMethod::Vendor->value,
    ]);

    $response = $this->actingAs($admin, 'web_admin')
        ->postJson("/api/v1/admin/creators/{$creator->ulid}/verify-identity", []);

    expect($response->status())->toBe(409);
    expect($response->json('errors.0.code'))->toBe('creator.kyc_already_verified');

    // Does NOT re-stamp the attribution columns — kyc_method stays vendor.
    $fresh = $creator->fresh();
    assert($fresh !== null);
    expect($fresh->kyc_method)->toBe(KycMethod::Vendor);
    expect($fresh->verified_by_user_id)->toBeNull();
});

it('returns 403 when the authenticated user is not platform_admin', function (): void {
    $creator = CreatorFactory::new()->createOne(['kyc_status' => KycStatus::None->value]);
    $otherUser = User::factory()->create([
        'type' => UserType::Creator,
        'two_factor_confirmed_at' => now(),
    ]);

    $response = $this->actingAs($otherUser, 'web_admin')
        ->postJson("/api/v1/admin/creators/{$creator->ulid}/verify-identity", []);

    expect($response->status())->toBe(403);
});

it('returns 401 when no admin is authenticated', function (): void {
    $creator = CreatorFactory::new()->createOne(['kyc_status' => KycStatus::None->value]);

    $response = $this->postJson("/api/v1/admin/creators/{$creator->ulid}/verify-identity", []);

    expect($response->status())->toBe(401);
});

it('CreatorPolicy::verifyIdentity allows platform_admin and denies others', function (): void {
    $policy = new CreatorPolicy;
    $creator = CreatorFactory::new()->createOne();
    $admin = User::factory()->create(['type' => UserType::PlatformAdmin]);
    $agencyUser = User::factory()->create(['type' => UserType::AgencyUser]);
    $creatorUser = User::factory()->create(['type' => UserType::Creator]);

    expect($policy->verifyIdentity($admin, $creator))->toBeTrue();
    expect($policy->verifyIdentity($agencyUser, $creator))->toBeFalse();
    expect($policy->verifyIdentity($creatorUser, $creator))->toBeFalse();
});
