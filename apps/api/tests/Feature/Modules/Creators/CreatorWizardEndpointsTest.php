<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Database\Factories\CreatorPortfolioItemFactory;
use App\Modules\Creators\Database\Factories\CreatorSocialAccountFactory;
use App\Modules\Creators\Enums\EsignStatus;
use App\Modules\Creators\Enums\KycStatus;
use App\Modules\Creators\Features\ContractSigningEnabled;
use App\Modules\Creators\Features\CreatorPayoutMethodEnabled;
use App\Modules\Creators\Features\KycVerificationEnabled;
use App\Modules\Creators\Integrations\Contracts\EsignProvider;
use App\Modules\Creators\Integrations\Contracts\KycProvider;
use App\Modules\Creators\Integrations\Contracts\PaymentProvider;
use App\Modules\Creators\Integrations\DataTransferObjects\AccountStatus;
use App\Modules\Creators\Integrations\DataTransferObjects\EsignEnvelopeResult;
use App\Modules\Creators\Integrations\DataTransferObjects\EsignWebhookEvent;
use App\Modules\Creators\Integrations\DataTransferObjects\KycInitiationResult;
use App\Modules\Creators\Integrations\DataTransferObjects\KycWebhookEvent;
use App\Modules\Creators\Integrations\DataTransferObjects\PaymentAccountResult;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Models\CreatorKycVerification;
use App\Modules\Creators\Models\CreatorPayoutMethod;
use App\Modules\Creators\Models\CreatorSocialAccount;
use App\Modules\Creators\Models\CreatorTaxProfile;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Helper: bind a fake provider that returns a stable result object so the
 * wizard step endpoints exercise the real service layer without hitting
 * the Deferred*Provider stubs (which throw by design in Sprint 3 Chunk 1).
 */
function bindFakeProviders(): void
{
    app()->bind(KycProvider::class, fn (): KycProvider => new class implements KycProvider
    {
        public function initiateVerification(Creator $creator): KycInitiationResult
        {
            return new KycInitiationResult('sess-fake', 'https://kyc.example/start', '2026-12-31T00:00:00Z');
        }

        public function getVerificationStatus(Creator $creator): KycStatus
        {
            return KycStatus::Pending;
        }

        public function verifyWebhookSignature(string $payload, string $signature): bool
        {
            return true;
        }

        public function parseWebhookEvent(string $payload): KycWebhookEvent
        {
            return new KycWebhookEvent('evt_fake', 'verification.completed', null, KycStatus::Verified, []);
        }
    });

    app()->bind(PaymentProvider::class, fn (): PaymentProvider => new class implements PaymentProvider
    {
        public function createConnectedAccount(Creator $creator): PaymentAccountResult
        {
            return new PaymentAccountResult('acct_fake', 'https://stripe.example/onboarding', '2026-12-31T00:00:00Z');
        }

        public function getAccountStatus(Creator $creator): AccountStatus
        {
            return new AccountStatus(false, false, false, []);
        }
    });

    app()->bind(EsignProvider::class, fn (): EsignProvider => new class implements EsignProvider
    {
        public function sendEnvelope(Creator $creator): EsignEnvelopeResult
        {
            return new EsignEnvelopeResult('env-fake', 'https://esign.example/sign', '2026-12-31T00:00:00Z');
        }

        public function getEnvelopeStatus(Creator $creator): EsignStatus
        {
            return EsignStatus::Sent;
        }

        public function verifyWebhookSignature(string $payload, string $signature): bool
        {
            return true;
        }

        public function parseWebhookEvent(string $payload): EsignWebhookEvent
        {
            return new EsignWebhookEvent('evt_fake', 'envelope.signed', null, EsignStatus::Signed, []);
        }
    });
}

beforeEach(function (): void {
    bindFakeProviders();

    // Sprint 3 Chunk 2 sub-step 9 — these endpoints exercise the
    // flag-ON happy path. The flag-OFF skip-path is asserted in
    // CreatorWizardFlagOffTest. Activating here keeps the
    // chunk-1-era tests intact under the new gating.
    Feature::activate(KycVerificationEnabled::NAME);
    Feature::activate(ContractSigningEnabled::NAME);
    Feature::activate(CreatorPayoutMethodEnabled::NAME);
});

it('PATCH /wizard/profile updates fields and recomputes completeness when all profile fields land', function (): void {
    $user = User::factory()->create();
    // Bootstrap state mirrors what CreatorBootstrapService produces
    // on sign-up — every wizard-related field is null. We then
    // pre-seed avatar_path because the avatar is uploaded via a
    // separate endpoint, not via this PATCH.
    $creator = CreatorFactory::new()->bootstrap()->createOne([
        'user_id' => $user->id,
        'avatar_path' => 'creators/seed/avatar/x.jpg',
    ]);

    $response = $this->actingAs($user)
        ->patchJson('/api/v1/creators/me/wizard/profile', [
            'display_name' => 'Catalyst',
            'country_code' => 'IT',
            'primary_language' => 'en',
            'categories' => ['lifestyle', 'music'],
        ]);

    $response->assertOk();
    $creator->refresh();
    expect($creator->display_name)->toBe('Catalyst')
        ->and($creator->profile_completeness_score)->toBeGreaterThan(0);
});

it('PATCH /wizard/profile validates the categories enum', function (): void {
    $user = User::factory()->create();
    CreatorFactory::new()->bootstrap()->createOne(['user_id' => $user->id]);

    $this->actingAs($user)
        ->patchJson('/api/v1/creators/me/wizard/profile', [
            'categories' => ['nonsense'],
        ])
        ->assertStatus(422);
});

it('PATCH /wizard/profile emits CreatorWizardProfileCompleted exactly once on first completion (#6 idempotent)', function (): void {
    $user = User::factory()->create();
    $creator = CreatorFactory::new()->bootstrap()->createOne([
        'user_id' => $user->id,
        'avatar_path' => 'creators/seed/avatar/x.jpg',
    ]);

    $payload = [
        'display_name' => 'Idem',
        'country_code' => 'IT',
        'primary_language' => 'en',
        'categories' => ['lifestyle'],
    ];

    $this->actingAs($user)->patchJson('/api/v1/creators/me/wizard/profile', $payload)->assertOk();
    $this->actingAs($user)->patchJson('/api/v1/creators/me/wizard/profile', $payload)->assertOk();

    expect(AuditLog::query()
        ->where('action', AuditAction::CreatorWizardProfileCompleted->value)
        ->where('subject_id', $creator->id)
        ->count())->toBe(1);
});

it('POST /wizard/social creates a social account and emits the audit row', function (): void {
    $user = User::factory()->create();
    $creator = CreatorFactory::new()->createOne(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson('/api/v1/creators/me/wizard/social', [
            'platform' => 'instagram',
            'handle' => '@catalyst',
            'profile_url' => 'https://instagram.com/catalyst',
        ])
        ->assertOk();

    expect(CreatorSocialAccount::where('creator_id', $creator->id)->count())->toBe(1);
    expect(AuditLog::query()
        ->where('action', AuditAction::CreatorWizardSocialCompleted->value)
        ->where('subject_id', $creator->id)
        ->count())->toBe(1);
});

it('POST /wizard/kyc returns the hosted-flow URL and persists a KYC row', function (): void {
    $user = User::factory()->create();
    $creator = CreatorFactory::new()->createOne(['user_id' => $user->id]);

    $response = $this->actingAs($user)->postJson('/api/v1/creators/me/wizard/kyc');

    $response->assertOk()
        ->assertJsonPath('data.session_id', 'sess-fake')
        ->assertJsonPath('data.hosted_flow_url', 'https://kyc.example/start');

    expect(CreatorKycVerification::where('creator_id', $creator->id)->count())->toBe(1);
    expect($creator->refresh()->kyc_status)->toBe(KycStatus::Pending);
});

it('PATCH /wizard/tax persists the encrypted tax profile', function (): void {
    $user = User::factory()->create();
    $creator = CreatorFactory::new()->createOne(['user_id' => $user->id]);

    $this->actingAs($user)
        ->patchJson('/api/v1/creators/me/wizard/tax', [
            'tax_form_type' => 'eu_self_employed',
            'legal_name' => 'Catalyst Srl',
            'tax_id' => 'IT12345678901',
            'address' => [
                'country_code' => 'it',
                'city' => 'Milan',
                'postal_code' => '20121',
                'street' => 'Via Roma 1',
            ],
        ])
        ->assertOk();

    $profile = CreatorTaxProfile::where('creator_id', $creator->id)->firstOrFail();
    expect($profile->legal_name)->toBe('Catalyst Srl')
        ->and($profile->tax_id_country)->toBe('IT')
        ->and($creator->refresh()->tax_profile_complete)->toBeTrue();
});

it('POST /wizard/payout returns the onboarding URL and persists the payout method', function (): void {
    $user = User::factory()->create();
    $creator = CreatorFactory::new()->createOne(['user_id' => $user->id]);

    $response = $this->actingAs($user)->postJson('/api/v1/creators/me/wizard/payout');

    $response->assertOk()
        ->assertJsonPath('data.account_id', 'acct_fake')
        ->assertJsonPath('data.onboarding_url', 'https://stripe.example/onboarding');

    expect(CreatorPayoutMethod::where('creator_id', $creator->id)->count())->toBe(1);
});

it('POST /wizard/contract returns the signing URL', function (): void {
    $user = User::factory()->create();
    CreatorFactory::new()->createOne(['user_id' => $user->id]);

    $response = $this->actingAs($user)->postJson('/api/v1/creators/me/wizard/contract');

    $response->assertOk()
        ->assertJsonPath('data.envelope_id', 'env-fake')
        ->assertJsonPath('data.signing_url', 'https://esign.example/sign');
});

it('POST /wizard/submit refuses to submit when wizard steps remain incomplete', function (): void {
    $user = User::factory()->create();
    CreatorFactory::new()->createOne(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson('/api/v1/creators/me/wizard/submit')
        ->assertStatus(409)
        ->assertJsonPath('errors.0.code', 'creator.wizard.incomplete');
});

it('POST /wizard/submit transitions to pending when every step is complete', function (): void {
    $user = User::factory()->create();
    $creator = CreatorFactory::new()->createOne([
        'user_id' => $user->id,
        'display_name' => 'Done',
        'country_code' => 'IT',
        'primary_language' => 'en',
        'categories' => ['music'],
        'avatar_path' => 'x',
        'kyc_status' => KycStatus::Verified,
        'tax_profile_complete' => true,
        'payout_method_set' => true,
        'signed_master_contract_id' => 1,
    ]);

    CreatorSocialAccountFactory::new()->for($creator)->create();
    CreatorPortfolioItemFactory::new()->for($creator)->create();

    $this->actingAs($user)
        ->postJson('/api/v1/creators/me/wizard/submit')
        ->assertOk()
        ->assertJsonPath('data.attributes.application_status', 'pending');

    expect(AuditLog::query()
        ->where('action', AuditAction::CreatorSubmitted->value)
        ->where('subject_id', $creator->id)
        ->count())->toBe(1);
});
