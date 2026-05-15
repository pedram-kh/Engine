<?php

declare(strict_types=1);

use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Database\Factories\CreatorKycVerificationFactory;
use App\Modules\Creators\Enums\KycStatus;
use App\Modules\Creators\Enums\KycVerificationStatus;
use App\Modules\Creators\Features\ContractSigningEnabled;
use App\Modules\Creators\Features\CreatorPayoutMethodEnabled;
use App\Modules\Creators\Features\KycVerificationEnabled;
use App\Modules\Creators\Http\Resources\CreatorResource;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Services\CompletenessScoreCalculator;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Pennant\Feature;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Sprint 3 Chunk 3 sub-step 1.
 *
 * Pins the bootstrap-response shape additions:
 *
 *   1. `wizard.flags` — the three Phase-1 feature-flag states the SPA
 *      consumes to render documented flag-OFF skip-paths (pause-condition-7
 *      closure).
 *   2. `admin_attributes` — appended when {@see CreatorResource::withAdmin()}
 *      is called. Closes Chunk 1 tech-debt entry 4 (CreatorResource
 *      symmetric shape) — one resource, two gated audiences.
 */
function makeResource(Creator $creator): CreatorResource
{
    return new CreatorResource($creator, app(CompletenessScoreCalculator::class));
}

beforeEach(function (): void {
    // Default-OFF stance per feature-flags.md; each test opts in.
    Feature::deactivate(KycVerificationEnabled::NAME);
    Feature::deactivate(CreatorPayoutMethodEnabled::NAME);
    Feature::deactivate(ContractSigningEnabled::NAME);
});

it('exposes wizard.flags reflecting all three flags OFF (default state)', function (): void {
    $user = User::factory()->create();
    $creator = CreatorFactory::new()->createOne(['user_id' => $user->id]);

    $payload = makeResource($creator)->toArray(Request::create('/'));

    expect($payload['wizard']['flags'])->toBe([
        KycVerificationEnabled::NAME => false,
        CreatorPayoutMethodEnabled::NAME => false,
        ContractSigningEnabled::NAME => false,
    ]);
});

it('exposes wizard.flags reflecting all three flags ON', function (): void {
    Feature::activate(KycVerificationEnabled::NAME);
    Feature::activate(CreatorPayoutMethodEnabled::NAME);
    Feature::activate(ContractSigningEnabled::NAME);

    $user = User::factory()->create();
    $creator = CreatorFactory::new()->createOne(['user_id' => $user->id]);

    $payload = makeResource($creator)->toArray(Request::create('/'));

    expect($payload['wizard']['flags'])->toBe([
        KycVerificationEnabled::NAME => true,
        CreatorPayoutMethodEnabled::NAME => true,
        ContractSigningEnabled::NAME => true,
    ]);
});

it('exposes wizard.flags reflecting mixed flag state (one ON, two OFF)', function (): void {
    Feature::activate(ContractSigningEnabled::NAME);

    $user = User::factory()->create();
    $creator = CreatorFactory::new()->createOne(['user_id' => $user->id]);

    $payload = makeResource($creator)->toArray(Request::create('/'));

    expect($payload['wizard']['flags'])->toBe([
        KycVerificationEnabled::NAME => false,
        CreatorPayoutMethodEnabled::NAME => false,
        ContractSigningEnabled::NAME => true,
    ]);
});

it('exposes click_through_accepted_at on the creator-facing view', function (): void {
    $user = User::factory()->create();
    $accepted = now()->subDay();
    $creator = CreatorFactory::new()->createOne([
        'user_id' => $user->id,
        'click_through_accepted_at' => $accepted,
    ]);

    $payload = makeResource($creator)->toArray(Request::create('/'));

    expect($payload['attributes']['click_through_accepted_at'])
        ->toBe($accepted->toIso8601String());
});

it('does NOT expose admin_attributes by default (creator-facing view)', function (): void {
    $user = User::factory()->create();
    $creator = CreatorFactory::new()->createOne([
        'user_id' => $user->id,
        'rejection_reason' => 'Profile incomplete',
    ]);

    $payload = makeResource($creator)->toArray(Request::create('/'));

    expect($payload)->not->toHaveKey('admin_attributes');
});

it('exposes admin_attributes when withAdmin() is called', function (): void {
    $user = User::factory()->create();
    $rejectedAt = now()->subDay();
    $lastActive = now()->subHours(3);
    $creator = CreatorFactory::new()->createOne([
        'user_id' => $user->id,
        'rejection_reason' => 'Profile incomplete',
        'rejected_at' => $rejectedAt,
        'last_active_at' => $lastActive,
    ]);

    $payload = makeResource($creator)->withAdmin()->toArray(Request::create('/'));

    expect($payload)->toHaveKey('admin_attributes');
    expect($payload['admin_attributes']['rejection_reason'])->toBe('Profile incomplete');
    expect($payload['admin_attributes']['rejected_at'])->toBe($rejectedAt->toIso8601String());
    expect($payload['admin_attributes']['last_active_at'])->toBe($lastActive->toIso8601String());
});

it('admin_attributes.kyc_verifications surfaces history in reverse chronological order', function (): void {
    $user = User::factory()->create();
    $creator = CreatorFactory::new()->createOne(['user_id' => $user->id]);

    $older = CreatorKycVerificationFactory::new()->createOne([
        'creator_id' => $creator->id,
        'provider' => 'mock',
        'status' => KycVerificationStatus::Expired,
        'started_at' => now()->subDays(10),
        'completed_at' => now()->subDays(10),
    ]);
    $newer = CreatorKycVerificationFactory::new()->createOne([
        'creator_id' => $creator->id,
        'provider' => 'mock',
        'status' => KycVerificationStatus::Passed,
        'started_at' => now()->subDay(),
        'completed_at' => now()->subDay(),
    ]);

    $creator->load('kycVerifications');

    $payload = makeResource($creator)->withAdmin()->toArray(Request::create('/'));

    $history = $payload['admin_attributes']['kyc_verifications'];
    expect($history)->toHaveCount(2);
    expect($history[0]['id'])->toBe($newer->ulid);
    expect($history[1]['id'])->toBe($older->ulid);
    expect($history[0]['provider'])->toBe('mock');
    expect($history[0]['status'])->toBe('passed');
});

it('admin_attributes.kyc_verifications NEVER surfaces decision_data or failure_reason (PII boundary)', function (): void {
    $user = User::factory()->create();
    $creator = CreatorFactory::new()->createOne(['user_id' => $user->id]);

    CreatorKycVerificationFactory::new()->createOne([
        'creator_id' => $creator->id,
        'provider' => 'mock',
        'status' => KycVerificationStatus::Failed,
        'decision_data' => ['sensitive' => 'pii payload'],
        'failure_reason' => 'document_quality_low',
        'started_at' => now()->subDay(),
    ]);

    $creator->load('kycVerifications');

    $payload = makeResource($creator)->withAdmin()->toArray(Request::create('/'));

    $entry = $payload['admin_attributes']['kyc_verifications'][0];
    expect($entry)->not->toHaveKey('decision_data');
    expect($entry)->not->toHaveKey('failure_reason');
});

it('withAdmin() is a fluent setter returning the same resource', function (): void {
    $creator = CreatorFactory::new()->createOne();
    $resource = makeResource($creator);

    expect($resource->withAdmin())->toBe($resource);
});

it('withAdmin(false) reverts to the creator-self view', function (): void {
    $creator = CreatorFactory::new()->createOne(['rejection_reason' => 'x']);
    $resource = makeResource($creator)->withAdmin()->withAdmin(false);

    $payload = $resource->toArray(Request::create('/'));

    expect($payload)->not->toHaveKey('admin_attributes');
});

it('kyc_verifications is empty array when creator has no verification history', function (): void {
    $user = User::factory()->create();
    $creator = CreatorFactory::new()->createOne(['user_id' => $user->id]);
    $creator->load('kycVerifications');

    $payload = makeResource($creator)->withAdmin()->toArray(Request::create('/'));

    expect($payload['admin_attributes']['kyc_verifications'])->toBe([]);
});

it('bootstrap response includes updated_at for the Welcome Back UX (Chunk 3 sub-step 2)', function (): void {
    $user = User::factory()->create();
    $creator = CreatorFactory::new()->createOne(['user_id' => $user->id]);

    $payload = makeResource($creator)->toArray(Request::create('/'));

    expect($payload['attributes'])->toHaveKey('updated_at');
    expect($payload['attributes']['updated_at'])->toBe($creator->updated_at->toIso8601String());
});

it('flag-ON kyc_status=Verified renders correctly alongside flags', function (): void {
    Feature::activate(KycVerificationEnabled::NAME);
    $user = User::factory()->create();
    $creator = CreatorFactory::new()->createOne([
        'user_id' => $user->id,
        'kyc_status' => KycStatus::Verified,
        'kyc_verified_at' => now(),
    ]);

    $payload = makeResource($creator)->toArray(Request::create('/'));

    expect($payload['attributes']['kyc_status'])->toBe('verified');
    expect($payload['wizard']['flags'][KycVerificationEnabled::NAME])->toBe(true);
});

it('flag-OFF + kyc_status=NotRequired both render — forensic distinction is preserved', function (): void {
    // Chunk 2 sub-step 9 invariant: KycStatus::NotRequired is the
    // submit-time forensic stamp; flag state can flip independently.
    $user = User::factory()->create();
    $creator = CreatorFactory::new()->createOne([
        'user_id' => $user->id,
        'kyc_status' => KycStatus::NotRequired,
    ]);

    $payload = makeResource($creator)->toArray(Request::create('/'));

    expect($payload['attributes']['kyc_status'])->toBe('not_required');
    expect($payload['wizard']['flags'][KycVerificationEnabled::NAME])->toBe(false);
});
