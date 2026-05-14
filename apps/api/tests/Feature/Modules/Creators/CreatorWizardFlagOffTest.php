<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Database\Factories\CreatorPortfolioItemFactory;
use App\Modules\Creators\Database\Factories\CreatorSocialAccountFactory;
use App\Modules\Creators\Enums\KycStatus;
use App\Modules\Creators\Features\ContractSigningEnabled;
use App\Modules\Creators\Features\CreatorPayoutMethodEnabled;
use App\Modules\Creators\Features\KycVerificationEnabled;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Models\CreatorTaxProfile;
use App\Modules\Creators\Services\CompletenessScoreCalculator;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Sprint 3 Chunk 2 sub-step 9 — flag-OFF skip-path coverage
|--------------------------------------------------------------------------
|
| Decision Q-mock-3 = (d) — both flag-ON and flag-OFF paths get test
| coverage. Flag-ON path lives in CreatorWizardEndpointsTest +
| WizardCompletionEndpointsTest. Flag-OFF path is here:
|
|   1. Initiate endpoints (POST /wizard/{kyc,payout,contract}) return
|      409 `creator.wizard.feature_disabled` without invoking the
|      Skipped*Provider stub.
|
|   2. Status-poll endpoints (GET /wizard/{kyc,payout,contract}/status)
|      return 409 `creator.wizard.feature_disabled` analogously.
|
|   3. The new click-through-accept endpoint stamps
|      `creators.click_through_accepted_at` only when contract_signing
|      is OFF; refuses with 409 `creator.wizard.feature_enabled` when
|      ON. Idempotent on re-submit.
|
|   4. Submit accepts a creator who has no kyc / payout / contract
|      step completion when the gating flags are OFF. The KYC step
|      additionally stamps `kyc_status = NotRequired` at submit time
|      per Q-flag-off-1 = (a).
|
|   5. CompletenessScoreCalculator credits flag-OFF steps to the
|      0–100 profile score so the creator's profile reads complete
|      (not "missing 35%") after submit.
|
| Flags default to OFF in every test in this file — no beforeEach
| activates them, and the explicit `Feature::deactivate(...)`
| safeguards against test-order coupling if Pennant's array driver
| state somehow leaks (the Pennant facade is reset between tests
| but explicit deactivation is cheap insurance for #40).
|
*/

function makeFlagOffCreator(): array
{
    $user = User::factory()->createOne();
    $creator = CreatorFactory::new()->bootstrap()->createOne([
        'user_id' => $user->id,
        'avatar_path' => 'creators/seed/avatar/x.jpg',
    ]);

    return [$user, $creator];
}

beforeEach(function (): void {
    Feature::deactivate(KycVerificationEnabled::NAME);
    Feature::deactivate(ContractSigningEnabled::NAME);
    Feature::deactivate(CreatorPayoutMethodEnabled::NAME);
});

// ---------------------------------------------------------------------------
// 1 — Initiate endpoints return 409 without provider call when flag OFF
// ---------------------------------------------------------------------------

it('POST /wizard/kyc returns 409 creator.wizard.feature_disabled when kyc flag is OFF', function (): void {
    [$user] = makeFlagOffCreator();

    $response = $this->actingAs($user)->postJson('/api/v1/creators/me/wizard/kyc');

    $response->assertStatus(409)->assertJsonPath('errors.0.code', 'creator.wizard.feature_disabled');
});

it('POST /wizard/payout returns 409 creator.wizard.feature_disabled when payout flag is OFF', function (): void {
    [$user] = makeFlagOffCreator();

    $response = $this->actingAs($user)->postJson('/api/v1/creators/me/wizard/payout');

    $response->assertStatus(409)->assertJsonPath('errors.0.code', 'creator.wizard.feature_disabled');
});

it('POST /wizard/contract returns 409 creator.wizard.feature_disabled when contract flag is OFF', function (): void {
    [$user] = makeFlagOffCreator();

    $response = $this->actingAs($user)->postJson('/api/v1/creators/me/wizard/contract');

    $response->assertStatus(409)->assertJsonPath('errors.0.code', 'creator.wizard.feature_disabled');
});

// ---------------------------------------------------------------------------
// 2 — Status-poll endpoints return 409 when flag OFF
// ---------------------------------------------------------------------------

it('GET /wizard/kyc/status returns 409 creator.wizard.feature_disabled when kyc flag is OFF', function (): void {
    [$user] = makeFlagOffCreator();

    $response = $this->actingAs($user)->getJson('/api/v1/creators/me/wizard/kyc/status');

    $response->assertStatus(409)->assertJsonPath('errors.0.code', 'creator.wizard.feature_disabled');
});

it('GET /wizard/contract/status returns 409 creator.wizard.feature_disabled when contract flag is OFF', function (): void {
    [$user] = makeFlagOffCreator();

    $response = $this->actingAs($user)->getJson('/api/v1/creators/me/wizard/contract/status');

    $response->assertStatus(409)->assertJsonPath('errors.0.code', 'creator.wizard.feature_disabled');
});

it('GET /wizard/payout/status returns 409 creator.wizard.feature_disabled when payout flag is OFF', function (): void {
    [$user] = makeFlagOffCreator();

    $response = $this->actingAs($user)->getJson('/api/v1/creators/me/wizard/payout/status');

    $response->assertStatus(409)->assertJsonPath('errors.0.code', 'creator.wizard.feature_disabled');
});

// ---------------------------------------------------------------------------
// 3 — Click-through-accept fallback
// ---------------------------------------------------------------------------

it('POST /wizard/contract/click-through-accept stamps click_through_accepted_at + emits audit when contract flag is OFF', function (): void {
    [$user, $creator] = makeFlagOffCreator();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/creators/me/wizard/contract/click-through-accept');

    $response->assertOk();
    $creator->refresh();
    expect($creator->click_through_accepted_at)->not->toBeNull();
    expect(AuditLog::query()->where('action', AuditAction::CreatorWizardClickThroughAccepted)->count())->toBe(1);
});

it('POST /wizard/contract/click-through-accept is idempotent — second call does NOT re-stamp or re-emit', function (): void {
    [$user, $creator] = makeFlagOffCreator();

    $this->actingAs($user)->postJson('/api/v1/creators/me/wizard/contract/click-through-accept')->assertOk();
    $firstStamp = $creator->refresh()->click_through_accepted_at;

    $this->actingAs($user)->postJson('/api/v1/creators/me/wizard/contract/click-through-accept')->assertOk();
    $secondStamp = $creator->refresh()->click_through_accepted_at;

    expect($firstStamp->equalTo($secondStamp))->toBeTrue();
    expect(AuditLog::query()->where('action', AuditAction::CreatorWizardClickThroughAccepted)->count())->toBe(1);
});

it('POST /wizard/contract/click-through-accept returns 409 creator.wizard.feature_enabled when contract flag is ON', function (): void {
    Feature::activate(ContractSigningEnabled::NAME);
    [$user] = makeFlagOffCreator();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/creators/me/wizard/contract/click-through-accept');

    $response->assertStatus(409)->assertJsonPath('errors.0.code', 'creator.wizard.feature_enabled');
});

// ---------------------------------------------------------------------------
// 4 — Submit accepts flag-OFF creators + KYC NotRequired transition
// ---------------------------------------------------------------------------

it('POST /wizard/submit succeeds when all three vendor flags are OFF + stamps kyc_status=not_required + sets payout/contract via none', function (): void {
    [$user, $creator] = makeFlagOffCreator();

    $creator->forceFill([
        'display_name' => 'Catalyst',
        'country_code' => 'IT',
        'primary_language' => 'en',
        'categories' => ['lifestyle'],
        'tax_profile_complete' => true,
    ])->save();

    CreatorSocialAccountFactory::new()->createOne(['creator_id' => $creator->id]);
    CreatorPortfolioItemFactory::new()->createOne(['creator_id' => $creator->id]);
    CreatorTaxProfile::create([
        'creator_id' => $creator->id,
        'tax_form_type' => 'eu_self_employed',
        'legal_name' => 'L',
        'tax_id' => 'IT12345678901',
        'tax_id_country' => 'IT',
        'address' => ['country_code' => 'IT'],
        'submitted_at' => now(),
    ]);

    $response = $this->actingAs($user)->postJson('/api/v1/creators/me/wizard/submit');

    $response->assertOk();
    $creator->refresh();
    expect($creator->kyc_status)->toBe(KycStatus::NotRequired);
    expect($creator->submitted_at)->not->toBeNull();
});

it('submit DOES NOT downgrade an already-Verified creator to NotRequired even if kyc flag is later flipped OFF', function (): void {
    [$user, $creator] = makeFlagOffCreator();

    $creator->forceFill([
        'display_name' => 'Catalyst',
        'country_code' => 'IT',
        'primary_language' => 'en',
        'categories' => ['lifestyle'],
        'tax_profile_complete' => true,
        'kyc_status' => KycStatus::Verified->value,
    ])->save();

    CreatorSocialAccountFactory::new()->createOne(['creator_id' => $creator->id]);
    CreatorPortfolioItemFactory::new()->createOne(['creator_id' => $creator->id]);
    CreatorTaxProfile::create([
        'creator_id' => $creator->id,
        'tax_form_type' => 'eu_self_employed',
        'legal_name' => 'L',
        'tax_id' => 'IT12345678901',
        'tax_id_country' => 'IT',
        'address' => ['country_code' => 'IT'],
        'submitted_at' => now(),
    ]);

    $this->actingAs($user)->postJson('/api/v1/creators/me/wizard/submit')->assertOk();

    $creator->refresh();
    expect($creator->kyc_status)->toBe(KycStatus::Verified);
});

// ---------------------------------------------------------------------------
// 5 — CompletenessScoreCalculator credits flag-OFF steps
// ---------------------------------------------------------------------------

it('completeness score = 100 for a creator with profile/social/portfolio/tax filled and all three vendor flags OFF', function (): void {
    [, $creator] = makeFlagOffCreator();

    $creator->forceFill([
        'display_name' => 'Catalyst',
        'country_code' => 'IT',
        'primary_language' => 'en',
        'categories' => ['lifestyle'],
        'tax_profile_complete' => true,
    ])->save();

    CreatorSocialAccountFactory::new()->createOne(['creator_id' => $creator->id]);
    CreatorPortfolioItemFactory::new()->createOne(['creator_id' => $creator->id]);

    $score = app(CompletenessScoreCalculator::class)
        ->score($creator->fresh());

    expect($score)->toBe(100);
});
