<?php

declare(strict_types=1);

use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Database\Factories\CreatorKycVerificationFactory;
use App\Modules\Creators\Enums\KycVerificationStatus;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Sprint 3 Chunk 3 sub-step 9.
 *
 * Backend coverage for the admin-facing read-only Creator endpoint:
 *
 *   GET /api/v1/admin/creators/{creator}
 *
 * The resource shape is exercised by `CreatorResourceTest`; this
 * file pins the HTTP boundary (auth guard, policy authorization,
 * 404 binding) and the admin_attributes / kyc_verifications shape
 * the endpoint surfaces (Refinement 2 closure for Chunk 1 tech-debt
 * entry 4).
 */
function makeAdminUser(): User
{
    return User::factory()->create([
        'type' => UserType::PlatformAdmin,
        'two_factor_confirmed_at' => now(),
    ]);
}

it('returns 401 when no admin is authenticated', function (): void {
    $creator = CreatorFactory::new()->createOne();

    $response = $this->getJson("/api/v1/admin/creators/{$creator->ulid}");

    expect($response->status())->toBe(401);
});

it('returns 403 when the authenticated user is a creator (wrong guard)', function (): void {
    $creator = CreatorFactory::new()->createOne();
    $creatorUser = User::factory()->create(['type' => UserType::Creator]);

    // Authenticate on the main SPA guard (web), not the admin guard.
    $response = $this->actingAs($creatorUser, 'web')
        ->getJson("/api/v1/admin/creators/{$creator->ulid}");

    // The admin route is gated by `auth:web_admin`; a web-guard session
    // is invisible to it → 401 (not 403). This pins the cross-SPA
    // session isolation guarantee from chunk 7.1.
    expect($response->status())->toBe(401);
});

it('returns 404 when the creator ulid does not match a row', function (): void {
    $admin = makeAdminUser();

    $response = $this->actingAs($admin, 'web_admin')
        ->getJson('/api/v1/admin/creators/01H00000000000000000000000');

    expect($response->status())->toBe(404);
});

it('returns the creator-self view + admin_attributes block to an authenticated platform admin', function (): void {
    $admin = makeAdminUser();
    $creator = CreatorFactory::new()->createOne([
        'rejection_reason' => 'Insufficient portfolio at submit time.',
    ]);

    $response = $this->actingAs($admin, 'web_admin')
        ->getJson("/api/v1/admin/creators/{$creator->ulid}");

    expect($response->status())->toBe(200);

    $payload = $response->json();
    expect($payload['data']['id'])->toBe($creator->ulid);
    expect($payload['data']['attributes']['display_name'])->toBe($creator->display_name);

    // Admin-only fields appear.
    expect($payload['data'])->toHaveKey('admin_attributes');
    expect($payload['data']['admin_attributes']['rejection_reason'])
        ->toBe('Insufficient portfolio at submit time.');
});

it('surfaces the kyc_verifications history (newest first, PII stripped)', function (): void {
    $admin = makeAdminUser();
    $creator = CreatorFactory::new()->createOne();

    $older = CreatorKycVerificationFactory::new()->createOne([
        'creator_id' => $creator->id,
        'provider' => 'mock_kyc',
        'status' => KycVerificationStatus::Failed,
        'started_at' => now()->subDays(2),
        'completed_at' => now()->subDays(2)->addMinutes(5),
    ]);
    $newer = CreatorKycVerificationFactory::new()->createOne([
        'creator_id' => $creator->id,
        'provider' => 'mock_kyc',
        'status' => KycVerificationStatus::Passed,
        'started_at' => now()->subHours(1),
        'completed_at' => now()->subMinutes(30),
    ]);

    $response = $this->actingAs($admin, 'web_admin')
        ->getJson("/api/v1/admin/creators/{$creator->ulid}");

    $verifications = $response->json('data.admin_attributes.kyc_verifications');
    expect($verifications)->toBeArray();
    expect(count($verifications))->toBe(2);
    // Newest first.
    expect($verifications[0]['id'])->toBe($newer->ulid);
    expect($verifications[1]['id'])->toBe($older->ulid);
    // PII never surfaces here.
    expect($verifications[0])->not->toHaveKey('decision_data');
    expect($verifications[0])->not->toHaveKey('failure_reason');
});

it('surfaces social_accounts and portfolio in the attributes block (sub-step 5 + 6)', function (): void {
    $admin = makeAdminUser();
    $creator = CreatorFactory::new()->createOne();
    $creator->socialAccounts()->create([
        'platform' => 'instagram',
        'handle' => 'creator_x',
        'profile_url' => 'https://instagram.com/creator_x',
        'platform_user_id' => 'ig:creator_x:12345',
        'is_primary' => true,
    ]);

    $response = $this->actingAs($admin, 'web_admin')
        ->getJson("/api/v1/admin/creators/{$creator->ulid}");

    expect($response->json('data.attributes.social_accounts'))->toBeArray();
    expect($response->json('data.attributes.social_accounts.0.handle'))->toBe('creator_x');
    expect($response->json('data.attributes.portfolio'))->toBeArray();
});
