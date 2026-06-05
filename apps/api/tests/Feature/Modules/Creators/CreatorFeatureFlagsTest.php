<?php

declare(strict_types=1);

use App\Modules\Creators\Features\ContractSigningEnabled;
use App\Modules\Creators\Features\CreatorPayoutMethodEnabled;
use App\Modules\Creators\Features\KycVerificationEnabled;
use App\Modules\Creators\Features\PerCampaignContractEnabled;
use App\Modules\Creators\Features\SocialVerificationEnabled;
use App\Modules\Creators\Integrations\Contracts\EsignProvider;
use App\Modules\Creators\Integrations\Contracts\KycProvider;
use App\Modules\Creators\Integrations\Contracts\PaymentProvider;
use App\Modules\Creators\Integrations\Contracts\SocialPlatformProvider;
use App\Modules\Creators\Integrations\Exceptions\FeatureDisabledException;
use App\Modules\Creators\Integrations\Stubs\SkippedEsignProvider;
use App\Modules\Creators\Integrations\Stubs\SkippedKycProvider;
use App\Modules\Creators\Integrations\Stubs\SkippedPaymentProvider;
use App\Modules\Creators\Integrations\Stubs\SkippedSocialProvider;
use App\Modules\Creators\Models\Creator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Sprint 3 Chunk 2 sub-step 2 — Pennant flags + Skipped-stub regression
|--------------------------------------------------------------------------
|
| Pins:
|
|   1. The three Phase-1 vendor-gating flags are registered with the
|      snake_case names from docs/feature-flags.md.
|
|   2. Each flag defaults to OFF when no operator activation has been
|      recorded (Phase 1 convention — operators flip globally via
|      `Feature::activate(<NAME>)` once the manual steps in
|      SPRINT-0-MANUAL-STEPS.md complete).
|
|   3. `Feature::active(<NAME>)` flips to TRUE after `Feature::activate`,
|      and FALSE again after `Feature::deactivate` — the round-trip
|      pin guards against silent default-resolver drift.
|
|   4. Each Skipped*Provider stub throws FeatureDisabledException with
|      a payload that names the contract + the OFF flag + the method,
|      so any code path that bypasses the flag check surfaces a clear
|      error rather than silently failing or making a vendor call
|      (#40 + docs/feature-flags.md "No silent vendor calls").
|
|   5. #1 source-inspection regression: the three Skipped*Provider
|      stubs are kept in lockstep with the contract surface — they
|      implement every method on their corresponding contract. A
|      future contract extension that forgets to extend the Skipped
|      stub re-opens the silent-vendor-call vector.
|
| Sub-step 8 binds the Skipped stubs into the container conditionally
| on the flag state; sub-step 2 ships the building blocks only.
|
*/

it('registers kyc_verification_enabled with default OFF', function (): void {
    expect(KycVerificationEnabled::NAME)->toBe('kyc_verification_enabled');
    expect(Feature::active(KycVerificationEnabled::NAME))->toBeFalse();
});

it('registers creator_payout_method_enabled with default OFF', function (): void {
    expect(CreatorPayoutMethodEnabled::NAME)->toBe('creator_payout_method_enabled');
    expect(Feature::active(CreatorPayoutMethodEnabled::NAME))->toBeFalse();
});

it('registers contract_signing_enabled with default OFF', function (): void {
    expect(ContractSigningEnabled::NAME)->toBe('contract_signing_enabled');
    expect(Feature::active(ContractSigningEnabled::NAME))->toBeFalse();
});

it('registers social_verification_enabled with a DRIVER-BASED default — ON under the mock driver (Sprint 9 Chunk 2, D-11)', function (): void {
    // Driver-based default (see SocialVerificationEnabled docblock): the mock
    // provider makes NO vendor calls, so the "no silent vendor calls" rationale
    // does not apply while driver=mock — the flag defaults ON so the
    // verification arc (incl. failure → manual resolution) runs out of the box.
    config(['integrations.social.driver' => 'mock']);
    expect(SocialVerificationEnabled::NAME)->toBe('social_verification_enabled');
    expect(Feature::active(SocialVerificationEnabled::NAME))->toBeTrue();
});

it('social_verification_enabled defaults OFF once a real social adapter is configured (no silent vendor calls)', function (): void {
    // The moment a real adapter is wired, the default flips back to OFF: an
    // un-provisioned real-driver instance must never reach the vendor. The
    // operator explicitly activates it once secrets are in place.
    config(['integrations.social.driver' => 'meta']);
    expect(Feature::active(SocialVerificationEnabled::NAME))->toBeFalse();
});

it('round-trips deactivate / activate for social_verification_enabled (default-ON under the mock driver)', function (): void {
    config(['integrations.social.driver' => 'mock']);
    expect(Feature::active(SocialVerificationEnabled::NAME))->toBeTrue('default-ON under mock');

    Feature::deactivate(SocialVerificationEnabled::NAME);
    expect(Feature::active(SocialVerificationEnabled::NAME))->toBeFalse('deactivate flips it OFF globally');

    Feature::activate(SocialVerificationEnabled::NAME);
    expect(Feature::active(SocialVerificationEnabled::NAME))->toBeTrue('activate flips it back ON globally');
});

it('registers per_campaign_contract_enabled with default ON (contract-gate-decouple chunk, D-1/D-2)', function (): void {
    // ⚠ The documented default-ON exception (D-2). This flag gates NO vendor —
    // the per-campaign manual flow is internal — so the "default OFF / no
    // silent vendor calls" rationale does not apply. It MUST register ON so the
    // manual flow ships to production without flipping the e-sign vendor flag.
    // Deliberately NOT added to the default-OFF expectation set below.
    expect(PerCampaignContractEnabled::NAME)->toBe('per_campaign_contract_enabled');
    expect(Feature::active(PerCampaignContractEnabled::NAME))->toBeTrue();
});

it('round-trips deactivate / activate for the default-ON per_campaign_contract_enabled flag', function (): void {
    // The mirror of the default-OFF round-trip below, but starting from the
    // default-ON state: the operator can flip the per-campaign flow OFF
    // globally (the break-revert) and back ON.
    expect(Feature::active(PerCampaignContractEnabled::NAME))->toBeTrue('default-ON');

    Feature::deactivate(PerCampaignContractEnabled::NAME);
    expect(Feature::active(PerCampaignContractEnabled::NAME))->toBeFalse('deactivate flips it OFF globally');

    Feature::activate(PerCampaignContractEnabled::NAME);
    expect(Feature::active(PerCampaignContractEnabled::NAME))->toBeTrue('activate flips it back ON globally');
});

it('round-trips activate / deactivate for each Phase-1 flag (no scope arg per Phase 1 convention)', function (): void {
    // Phase 1 invocation pattern: scope-less / global. The default
    // store is the array driver in the test env (config/pennant.php),
    // so each test starts from the default-resolver state. The
    // round-trip is the structural pin that operators flipping a flag
    // globally via `Feature::activate(<NAME>)` actually reaches the
    // `Feature::active(<NAME>)` call sites — i.e., scope semantics
    // line up between activation and the consumer check.
    foreach (
        [
            KycVerificationEnabled::NAME,
            CreatorPayoutMethodEnabled::NAME,
            ContractSigningEnabled::NAME,
            // social_verification_enabled is intentionally excluded — it has a
            // driver-based default (ON under the mock driver, the test env),
            // so it does not start from the default-OFF state. Its own default
            // + round-trip pins live above.
        ] as $name
    ) {
        expect(Feature::active($name))->toBeFalse("default-OFF for {$name}");

        Feature::activate($name);
        expect(Feature::active($name))->toBeTrue("activate flips {$name} ON globally");

        Feature::deactivate($name);
        expect(Feature::active($name))->toBeFalse("deactivate flips {$name} OFF globally");
    }
});

it('SkippedKycProvider throws FeatureDisabledException naming the OFF flag + method', function (): void {
    $stub = new SkippedKycProvider;
    $stub->initiateVerification(new Creator);
})->throws(
    FeatureDisabledException::class,
    "Integration provider 'KycProvider' is bound to a Skipped stub because feature flag 'kyc_verification_enabled' is OFF. Method called: initiateVerification.",
);

it('SkippedEsignProvider throws FeatureDisabledException naming the OFF flag + method', function (): void {
    $stub = new SkippedEsignProvider;
    $stub->sendEnvelope(new Creator);
})->throws(
    FeatureDisabledException::class,
    "Integration provider 'EsignProvider' is bound to a Skipped stub because feature flag 'contract_signing_enabled' is OFF. Method called: sendEnvelope.",
);

it('SkippedPaymentProvider throws FeatureDisabledException naming the OFF flag + method', function (): void {
    $stub = new SkippedPaymentProvider;
    $stub->createConnectedAccount(new Creator);
})->throws(
    FeatureDisabledException::class,
    "Integration provider 'PaymentProvider' is bound to a Skipped stub because feature flag 'creator_payout_method_enabled' is OFF. Method called: createConnectedAccount.",
);

it('SkippedSocialProvider throws FeatureDisabledException naming the OFF flag + method', function (): void {
    $stub = new SkippedSocialProvider;
    $stub->verifyPostUrl('@creator', 'https://instagram.com/p/abc');
})->throws(
    FeatureDisabledException::class,
    "Integration provider 'SocialPlatformProvider' is bound to a Skipped stub because feature flag 'social_verification_enabled' is OFF. Method called: verifyPostUrl.",
);

it('source-inspection: each Skipped*Provider implements every method on its contract (#1)', function (): void {
    // Lockstep regression. Sprint 3 Chunk 2 sub-step 3 extends the
    // three contracts (KYC: 1 → 4 methods, eSign: 1 → 4, Payment:
    // 1 → 2). When that lands, the Skipped stubs MUST grow in
    // lockstep — a contract method without a Skipped override would
    // resolve to PHP's "abstract method not implemented" fatal at
    // class-load time (which is itself a backstop), but the
    // source-inspection check makes the dependency explicit.
    //
    // If this test fails after a contract extension, extend the
    // corresponding Skipped*Provider with a method that throws
    // FeatureDisabledException::for(...).
    $pairs = [
        [KycProvider::class, SkippedKycProvider::class, KycVerificationEnabled::NAME],
        [EsignProvider::class, SkippedEsignProvider::class, ContractSigningEnabled::NAME],
        [PaymentProvider::class, SkippedPaymentProvider::class, CreatorPayoutMethodEnabled::NAME],
        [SocialPlatformProvider::class, SkippedSocialProvider::class, SocialVerificationEnabled::NAME],
    ];

    foreach ($pairs as [$contract, $stub, $flag]) {
        $contractMethods = array_map(
            static fn (ReflectionMethod $m): string => $m->getName(),
            (new ReflectionClass($contract))->getMethods(ReflectionMethod::IS_PUBLIC),
        );
        sort($contractMethods);

        $stubMethods = array_filter(
            array_map(
                static fn (ReflectionMethod $m): string => $m->getName(),
                (new ReflectionClass($stub))->getMethods(ReflectionMethod::IS_PUBLIC),
            ),
            static fn (string $name): bool => $name !== '__construct',
        );
        sort($stubMethods);

        expect($stubMethods)->toBe(
            $contractMethods,
            "{$stub} must implement every method on {$contract} so the no-silent-vendor-calls invariant survives a contract extension. Flag: {$flag}.",
        );
    }
});
