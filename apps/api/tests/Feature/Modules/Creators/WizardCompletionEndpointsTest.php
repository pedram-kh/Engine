<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Creators\Database\Factories\CreatorFactory;
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
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    // Sub-step 9 status-poll endpoints are flag-gated. The tests
    // here exercise the flag-ON happy path; the flag-OFF skip-path
    // is asserted in CreatorWizardFlagOffTest.
    Feature::activate(KycVerificationEnabled::NAME);
    Feature::activate(ContractSigningEnabled::NAME);
    Feature::activate(CreatorPayoutMethodEnabled::NAME);
});

/**
 * Bind a KycProvider stub returning the given status. The other
 * provider methods are stubbed with no-op deterministic
 * placeholders — this test surface only exercises the
 * status-poll path.
 */
function bindKycStatus(KycStatus $status): void
{
    app()->bind(KycProvider::class, fn (): KycProvider => new class($status) implements KycProvider
    {
        public function __construct(private readonly KycStatus $status) {}

        public function initiateVerification(Creator $creator): KycInitiationResult
        {
            return new KycInitiationResult('sess', 'https://x', '2030-01-01T00:00:00Z');
        }

        public function getVerificationStatus(Creator $creator): KycStatus
        {
            return $this->status;
        }

        public function verifyWebhookSignature(string $payload, string $signature): bool
        {
            return true;
        }

        public function parseWebhookEvent(string $payload): KycWebhookEvent
        {
            return new KycWebhookEvent('evt', 'verification.completed', null, KycStatus::Verified, []);
        }
    });
}

function bindEsignStatus(EsignStatus $status): void
{
    app()->bind(EsignProvider::class, fn (): EsignProvider => new class($status) implements EsignProvider
    {
        public function __construct(private readonly EsignStatus $status) {}

        public function sendEnvelope(Creator $creator): EsignEnvelopeResult
        {
            return new EsignEnvelopeResult('env', 'https://x', '2030-01-01T00:00:00Z');
        }

        public function getEnvelopeStatus(Creator $creator): EsignStatus
        {
            return $this->status;
        }

        public function verifyWebhookSignature(string $payload, string $signature): bool
        {
            return true;
        }

        public function parseWebhookEvent(string $payload): EsignWebhookEvent
        {
            return new EsignWebhookEvent('evt', 'envelope.signed', null, EsignStatus::Signed, []);
        }
    });
}

function bindAccountStatus(AccountStatus $status): void
{
    app()->bind(PaymentProvider::class, fn (): PaymentProvider => new class($status) implements PaymentProvider
    {
        public function __construct(private readonly AccountStatus $status) {}

        public function createConnectedAccount(Creator $creator): PaymentAccountResult
        {
            return new PaymentAccountResult('acct', 'https://x', '2030-01-01T00:00:00Z');
        }

        public function getAccountStatus(Creator $creator): AccountStatus
        {
            return $this->status;
        }
    });
}

function makeCompletionCreator(): array
{
    $user = User::factory()->createOne();
    $creator = CreatorFactory::new()->bootstrap()->createOne(['user_id' => $user->id]);

    return [$user, $creator];
}

// ---------------------------------------------------------------------------
// KYC status-poll
// ---------------------------------------------------------------------------

it('GET /wizard/kyc/status returns 401 when unauthenticated', function (): void {
    $response = $this->getJson('/api/v1/creators/me/wizard/kyc/status');
    $response->assertStatus(401);
});

it('GET /wizard/kyc/status with verified provider transitions kyc_status + emits CreatorWizardKycCompleted audit', function (): void {
    bindKycStatus(KycStatus::Verified);
    [$user, $creator] = makeCompletionCreator();

    $response = $this->actingAs($user)->getJson('/api/v1/creators/me/wizard/kyc/status');

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'verified')
        ->assertJsonPath('data.transitioned', true);

    $creator->refresh();
    expect($creator->kyc_status)->toBe(KycStatus::Verified)
        ->and($creator->kyc_verified_at)->not->toBeNull();

    expect(AuditLog::query()->where('action', AuditAction::CreatorWizardKycCompleted)->count())->toBe(1);
});

it('GET /wizard/kyc/status is idempotent — re-poll after Verified does NOT re-emit the audit row (#6)', function (): void {
    bindKycStatus(KycStatus::Verified);
    [$user, $creator] = makeCompletionCreator();

    $this->actingAs($user)->getJson('/api/v1/creators/me/wizard/kyc/status')->assertStatus(200);
    $this->actingAs($user)->getJson('/api/v1/creators/me/wizard/kyc/status')->assertStatus(200);

    expect(AuditLog::query()->where('action', AuditAction::CreatorWizardKycCompleted)->count())->toBe(1);
});

it('GET /wizard/kyc/status with non-terminal status reports transition without emitting completion audit', function (): void {
    bindKycStatus(KycStatus::Pending);
    [$user, $creator] = makeCompletionCreator();

    $response = $this->actingAs($user)->getJson('/api/v1/creators/me/wizard/kyc/status');

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'pending');

    expect(AuditLog::query()->where('action', AuditAction::CreatorWizardKycCompleted)->count())->toBe(0);
});

it('GET /wizard/kyc/return invokes the same pipeline as /status', function (): void {
    bindKycStatus(KycStatus::Verified);
    [$user, $creator] = makeCompletionCreator();

    $response = $this->actingAs($user)->getJson('/api/v1/creators/me/wizard/kyc/return');

    $response->assertStatus(200)->assertJsonPath('data.status', 'verified');
    $creator->refresh();
    expect($creator->kyc_status)->toBe(KycStatus::Verified);
});

// ---------------------------------------------------------------------------
// Contract status-poll
// ---------------------------------------------------------------------------

it('GET /wizard/contract/status with Signed provider populates signed_master_contract_id + emits completion audit', function (): void {
    bindEsignStatus(EsignStatus::Signed);
    [$user, $creator] = makeCompletionCreator();

    $response = $this->actingAs($user)->getJson('/api/v1/creators/me/wizard/contract/status');

    $response->assertStatus(200)->assertJsonPath('data.status', 'signed');

    $creator->refresh();
    expect($creator->signed_master_contract_id)->not->toBeNull();
    expect(AuditLog::query()->where('action', AuditAction::CreatorWizardContractCompleted)->count())->toBe(1);
});

it('GET /wizard/contract/status is idempotent on re-poll after Signed (#6)', function (): void {
    bindEsignStatus(EsignStatus::Signed);
    [$user, $creator] = makeCompletionCreator();

    $this->actingAs($user)->getJson('/api/v1/creators/me/wizard/contract/status')->assertStatus(200);
    $this->actingAs($user)->getJson('/api/v1/creators/me/wizard/contract/status')->assertStatus(200);

    expect(AuditLog::query()->where('action', AuditAction::CreatorWizardContractCompleted)->count())->toBe(1);
});

it('GET /wizard/contract/status with Declined leaves signed_master_contract_id NULL and emits no completion audit', function (): void {
    bindEsignStatus(EsignStatus::Declined);
    [$user, $creator] = makeCompletionCreator();

    $this->actingAs($user)->getJson('/api/v1/creators/me/wizard/contract/status')->assertStatus(200);

    $creator->refresh();
    expect($creator->signed_master_contract_id)->toBeNull();
    expect(AuditLog::query()->where('action', AuditAction::CreatorWizardContractCompleted)->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Payout status-poll
// ---------------------------------------------------------------------------

it('GET /wizard/payout/status with fully-onboarded account flips payout_method_set + emits completion audit', function (): void {
    bindAccountStatus(new AccountStatus(true, true, true, []));
    [$user, $creator] = makeCompletionCreator();

    $response = $this->actingAs($user)->getJson('/api/v1/creators/me/wizard/payout/status');

    $response->assertStatus(200)
        ->assertJsonPath('data.fully_onboarded', true)
        ->assertJsonPath('data.transitioned', true);

    $creator->refresh();
    expect($creator->payout_method_set)->toBeTrue();
    expect(AuditLog::query()->where('action', AuditAction::CreatorWizardPayoutCompleted)->count())->toBe(1);
});

it('GET /wizard/payout/status is idempotent — re-poll after onboarding does NOT re-emit (#6)', function (): void {
    bindAccountStatus(new AccountStatus(true, true, true, []));
    [$user, $creator] = makeCompletionCreator();

    $this->actingAs($user)->getJson('/api/v1/creators/me/wizard/payout/status')->assertStatus(200);
    $this->actingAs($user)->getJson('/api/v1/creators/me/wizard/payout/status')->assertStatus(200);

    expect(AuditLog::query()->where('action', AuditAction::CreatorWizardPayoutCompleted)->count())->toBe(1);
});

it('GET /wizard/payout/status with not-fully-onboarded account leaves payout_method_set false', function (): void {
    bindAccountStatus(new AccountStatus(false, false, true, ['external_account']));
    [$user, $creator] = makeCompletionCreator();

    $response = $this->actingAs($user)->getJson('/api/v1/creators/me/wizard/payout/status');

    $response->assertStatus(200)
        ->assertJsonPath('data.fully_onboarded', false)
        ->assertJsonPath('data.transitioned', false);

    $creator->refresh();
    expect($creator->payout_method_set)->toBeFalse();
    expect(AuditLog::query()->where('action', AuditAction::CreatorWizardPayoutCompleted)->count())->toBe(0);
});

it('GET /wizard/payout/return is the redirect-bounce twin of /status', function (): void {
    bindAccountStatus(new AccountStatus(true, true, true, []));
    [$user, $creator] = makeCompletionCreator();

    $this->actingAs($user)->getJson('/api/v1/creators/me/wizard/payout/return')->assertStatus(200);

    $creator->refresh();
    expect($creator->payout_method_set)->toBeTrue();
});
