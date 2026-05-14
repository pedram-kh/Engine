<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Vendor integration configuration
|--------------------------------------------------------------------------
|
| Sprint 3 Chunk 2 introduces this file; previously, the Creators module
| had no externally-configurable integration settings. The driver
| selection follows the **per-provider environment variable**
| convention (Q-driver-convention in the chunk-2 plan, replacing the
| earlier "single INTEGRATIONS_DRIVER env var" sketch). This keeps
| mixed-vendor staging environments tractable: KYC can flip to a real
| vendor while eSign and payment stay on `mock` independently.
|
| Sprint 3 ships only the `mock` driver per provider. Real-vendor
| drivers register additional cases in {@see CreatorsServiceProvider}'s
| binding map as they land in Sprint 4 (KYC), Sprint 7 (Stripe), and
| Sprint 9 (eSign).
|
| All real-vendor secrets live in AWS Secrets Manager per
| docs/06-INTEGRATIONS.md § 1.2; this file holds **non-secret**
| configuration only. The `mock_webhook_secret` values below are
| explicitly non-production HMAC keys used by the local mock-vendor
| flow + by feature tests; they are never used to validate real
| vendor traffic and never written to AWS Secrets Manager.
|
*/

return [

    'kyc' => [
        /*
         * Driver selection. Falls through CreatorsServiceProvider's
         * binding map (sub-step 8): 'mock' resolves to MockKycProvider
         * when kyc_verification_enabled is ON, otherwise the Skipped
         * stub binds. Real-adapter values land in Sprint 4+.
         */
        'driver' => env('KYC_PROVIDER', 'mock'),

        /*
         * HMAC-SHA256 secret used by MockKycProvider's webhook-
         * signature verification + by the mock-vendor "Complete"
         * button when it dispatches the simulated webhook
         * (Q-mock-webhook-dispatch = (b)). Deterministic across
         * tests so feature tests can recompute the signature.
         */
        'mock_webhook_secret' => env('KYC_MOCK_WEBHOOK_SECRET', 'mock-kyc-webhook-secret-do-not-use-in-production'),
    ],

    'esign' => [
        'driver' => env('ESIGN_PROVIDER', 'mock'),
        'mock_webhook_secret' => env('ESIGN_MOCK_WEBHOOK_SECRET', 'mock-esign-webhook-secret-do-not-use-in-production'),
    ],

    'payment' => [
        /*
         * Stripe Connect onboarding-completion uses status-poll only
         * in Sprint 3 (Q-stripe-no-webhook-acceptable in the chunk-2
         * plan). The webhook handling — and a corresponding
         * `mock_webhook_secret` — lands in Sprint 10 alongside the
         * `account.updated` handler.
         */
        'driver' => env('PAYMENT_PROVIDER', 'mock'),
    ],

];
