<?php

declare(strict_types=1);

use App\Modules\Creators\Features\ContractSigningEnabled;
use App\Modules\Creators\Features\CreatorPayoutMethodEnabled;
use App\Modules\Creators\Features\KycVerificationEnabled;
use App\Modules\Creators\Integrations\Contracts\EsignProvider;
use App\Modules\Creators\Integrations\Contracts\KycProvider;
use App\Modules\Creators\Integrations\Contracts\PaymentProvider;
use App\Modules\Creators\Integrations\Exceptions\ProviderNotBoundException;
use App\Modules\Creators\Integrations\Mock\MockEsignProvider;
use App\Modules\Creators\Integrations\Mock\MockKycProvider;
use App\Modules\Creators\Integrations\Mock\MockPaymentProvider;
use App\Modules\Creators\Integrations\Stubs\DeferredEsignProvider;
use App\Modules\Creators\Integrations\Stubs\DeferredKycProvider;
use App\Modules\Creators\Integrations\Stubs\DeferredPaymentProvider;
use App\Modules\Creators\Integrations\Stubs\SkippedEsignProvider;
use App\Modules\Creators\Integrations\Stubs\SkippedKycProvider;
use App\Modules\Creators\Integrations\Stubs\SkippedPaymentProvider;
use App\Modules\Creators\Models\Creator;
use Laravel\Pennant\Feature;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| Sprint 3 Chunk 2 — flag-conditional provider bindings (sub-step 8)
|--------------------------------------------------------------------------
|
| Sprint 3 Chunk 1 bound the Deferred-throws-on-call stubs as the only
| provider implementation. Chunk 2 sub-step 8 swaps that for a
| flag-conditional + driver-aware resolver in
| {@see \App\Modules\Creators\CreatorsServiceProvider::register()}.
|
| Resolution order per provider:
|
|   1. Pennant flag OFF → Skipped*Provider (throws
|      FeatureDisabledException). Phase 1 default state — closes the
|      no-silent-vendor-calls invariant from docs/feature-flags.md.
|
|   2. Flag ON + driver = 'mock' (Sprint 3 default) → Mock*Provider.
|
|   3. Flag ON + driver = anything else (no real adapters ship in
|      Sprint 3) → falls through to Deferred*Provider so the
|      misconfiguration fails loudly via ProviderNotBoundException
|      at first call rather than silent vendor traffic.
|
| The Deferred-stub method-throws regression + Sprint-3-completion
| surface assertion stay in place — they're the structural pins
| #34 cross-chunk handoff verification leans on.
|
*/

it('with flag OFF, KycProvider resolves to SkippedKycProvider (Phase 1 default)', function (): void {
    Feature::deactivate(KycVerificationEnabled::NAME);

    expect(app(KycProvider::class))->toBeInstanceOf(SkippedKycProvider::class);
});

it('with flag OFF, EsignProvider resolves to SkippedEsignProvider', function (): void {
    Feature::deactivate(ContractSigningEnabled::NAME);

    expect(app(EsignProvider::class))->toBeInstanceOf(SkippedEsignProvider::class);
});

it('with flag OFF, PaymentProvider resolves to SkippedPaymentProvider', function (): void {
    Feature::deactivate(CreatorPayoutMethodEnabled::NAME);

    expect(app(PaymentProvider::class))->toBeInstanceOf(SkippedPaymentProvider::class);
});

it('with flag ON + driver=mock, KycProvider resolves to MockKycProvider', function (): void {
    Feature::activate(KycVerificationEnabled::NAME);
    config(['integrations.kyc.driver' => 'mock']);

    expect(app(KycProvider::class))->toBeInstanceOf(MockKycProvider::class);
});

it('with flag ON + driver=mock, EsignProvider resolves to MockEsignProvider', function (): void {
    Feature::activate(ContractSigningEnabled::NAME);
    config(['integrations.esign.driver' => 'mock']);

    expect(app(EsignProvider::class))->toBeInstanceOf(MockEsignProvider::class);
});

it('with flag ON + driver=mock, PaymentProvider resolves to MockPaymentProvider', function (): void {
    Feature::activate(CreatorPayoutMethodEnabled::NAME);
    config(['integrations.payment.driver' => 'mock']);

    expect(app(PaymentProvider::class))->toBeInstanceOf(MockPaymentProvider::class);
});

it('with flag ON + unknown driver, KycProvider falls through to DeferredKycProvider (no silent vendor)', function (): void {
    Feature::activate(KycVerificationEnabled::NAME);
    config(['integrations.kyc.driver' => 'unknown_real_vendor']);

    // The fall-through is the explicit "fail loud at the first call"
    // posture documented on the resolver — a misconfigured driver
    // string in production resolves to the throws-on-call stub
    // rather than silently routing to a wrong adapter.
    expect(app(KycProvider::class))->toBeInstanceOf(DeferredKycProvider::class);
});

it('the binding closure is lazy — flag flips between resolutions are observed', function (): void {
    Feature::deactivate(KycVerificationEnabled::NAME);
    expect(app()->make(KycProvider::class))->toBeInstanceOf(SkippedKycProvider::class);

    Feature::activate(KycVerificationEnabled::NAME);
    config(['integrations.kyc.driver' => 'mock']);
    expect(app()->make(KycProvider::class))->toBeInstanceOf(MockKycProvider::class);
});

// ---------------------------------------------------------------------------
// Deferred-stub regression — preserved across the binding swap (#40)
// ---------------------------------------------------------------------------

it('DeferredKycProvider throws ProviderNotBoundException on every method', function (): void {
    $stub = new DeferredKycProvider;
    $creator = new Creator;

    foreach (['initiateVerification', 'getVerificationStatus', 'verifyWebhookSignature', 'parseWebhookEvent'] as $method) {
        $threw = false;
        try {
            match ($method) {
                'initiateVerification', 'getVerificationStatus' => $stub->{$method}($creator),
                'verifyWebhookSignature' => $stub->verifyWebhookSignature('payload', 'sig'),
                'parseWebhookEvent' => $stub->parseWebhookEvent('payload'),
            };
        } catch (ProviderNotBoundException $e) {
            $threw = true;
            expect($e->getMessage())
                ->toContain("'KycProvider'")
                ->and($e->getMessage())->toContain("Method called: {$method}");
        }
        expect($threw)->toBeTrue("DeferredKycProvider::{$method}() did not throw");
    }
});

it('DeferredEsignProvider throws ProviderNotBoundException on every method', function (): void {
    $stub = new DeferredEsignProvider;
    $creator = new Creator;

    foreach (['sendEnvelope', 'getEnvelopeStatus', 'verifyWebhookSignature', 'parseWebhookEvent'] as $method) {
        $threw = false;
        try {
            match ($method) {
                'sendEnvelope', 'getEnvelopeStatus' => $stub->{$method}($creator),
                'verifyWebhookSignature' => $stub->verifyWebhookSignature('payload', 'sig'),
                'parseWebhookEvent' => $stub->parseWebhookEvent('payload'),
            };
        } catch (ProviderNotBoundException $e) {
            $threw = true;
            expect($e->getMessage())
                ->toContain("'EsignProvider'")
                ->and($e->getMessage())->toContain("Method called: {$method}");
        }
        expect($threw)->toBeTrue("DeferredEsignProvider::{$method}() did not throw");
    }
});

it('DeferredPaymentProvider throws ProviderNotBoundException on every method', function (): void {
    $stub = new DeferredPaymentProvider;
    $creator = new Creator;

    foreach (['createConnectedAccount', 'getAccountStatus'] as $method) {
        $threw = false;
        try {
            $stub->{$method}($creator);
        } catch (ProviderNotBoundException $e) {
            $threw = true;
            expect($e->getMessage())
                ->toContain("'PaymentProvider'")
                ->and($e->getMessage())->toContain("Method called: {$method}");
        }
        expect($threw)->toBeTrue("DeferredPaymentProvider::{$method}() did not throw");
    }
});

it('the three contracts each define exactly the Sprint-3-completion surface (KYC: 4, eSign: 4, Payment: 2)', function (): void {
    // Sprint 3 Chunk 2 planned reset of the chunk-1 "exactly one
    // method" pin — the hybrid completion architecture (Decision
    // A = (c) in the chunk-2 plan) lands all status-poll +
    // webhook methods in lockstep with the wizard endpoints that
    // call them. Source-inspection regression (#1).
    //
    // If a future sprint extends a contract, this assertion MUST
    // be updated in lockstep with the contract change so the
    // intended Sprint-N surface is explicit. A silent contract
    // extension that passes this test (because someone updated it
    // without a code review) is exactly the failure mode #34
    // cross-chunk handoff verification protects against.
    $expectedMethods = [
        KycProvider::class => [
            'getVerificationStatus',
            'initiateVerification',
            'parseWebhookEvent',
            'verifyWebhookSignature',
        ],
        EsignProvider::class => [
            'getEnvelopeStatus',
            'parseWebhookEvent',
            'sendEnvelope',
            'verifyWebhookSignature',
        ],
        PaymentProvider::class => [
            'createConnectedAccount',
            'getAccountStatus',
        ],
    ];

    foreach ($expectedMethods as $contract => $expected) {
        $reflection = new ReflectionClass($contract);
        $actual = collect($reflection->getMethods(ReflectionMethod::IS_PUBLIC))
            ->map(fn (ReflectionMethod $m): string => $m->getName())
            ->sort()
            ->values()
            ->all();

        sort($expected);

        expect($actual)->toBe($expected, "{$contract} should expose exactly the Sprint-3-completion surface.");
    }
});

it('each contract docblock cites the Sprint-3 completion surface for #34 cross-chunk handoff verification', function (): void {
    $contracts = [
        KycProvider::class,
        EsignProvider::class,
        PaymentProvider::class,
    ];

    foreach ($contracts as $contract) {
        $doc = (new ReflectionClass($contract))->getDocComment();
        expect($doc)->toBeString()
            ->and($doc)->toContain('Sprint 3 completion surface');
    }
});
