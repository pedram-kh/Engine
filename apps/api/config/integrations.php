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
| Sprint 3 shipped only the `mock` driver per provider. Real-vendor
| drivers register additional cases in {@see CreatorsServiceProvider}'s
| binding map as they land. Sprint 4 Chunk 2 adds the `stripe` payment
| driver (real Stripe Connect onboarding adapter, test-mode); it is
| bound-but-unreachable in production because `creator_payout_method_enabled`
| stays OFF (D-c2-9) — reached only in test/staging where the flag is ON
| and PAYMENT_PROVIDER=stripe.
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
         * Driver selection. 'mock' (default) resolves to
         * MockPaymentProvider when creator_payout_method_enabled is ON;
         * 'stripe' resolves to the real StripePaymentProvider (Sprint 4
         * Chunk 2). Otherwise the Skipped stub binds (flag OFF).
         */
        'driver' => env('PAYMENT_PROVIDER', 'mock'),

        /*
         * HMAC-SHA256 secret for the mock payment webhook path — used
         * by MockPaymentProvider::verifyWebhookSignature() and by
         * feature tests that recompute the signature. Non-production;
         * never validates real Stripe traffic, never written to AWS
         * Secrets Manager. Mirrors the kyc/esign mock secrets.
         */
        'mock_webhook_secret' => env('PAYMENT_MOCK_WEBHOOK_SECRET', 'mock-payment-webhook-secret-do-not-use-in-production'),

        /*
         * Real Stripe Connect adapter configuration (Sprint 4 Chunk 2).
         *
         * `secret_key`, `webhook_secret` and `connect_client_id` are
         * SECRET material: in non-local environments they are hydrated
         * into these env vars from AWS Secrets Manager
         * (`catalyst/${env}/api/stripe`) per docs/06-INTEGRATIONS.md
         * § 1.2 — never committed to env files or code. In test-mode
         * they are Stripe test keys (`sk_test_*`) and the dashboard /
         * Stripe-CLI webhook endpoint signing secret (`whsec_*`).
         *
         * `return_url` / `refresh_url` are NON-secret — the SPA wizard
         * payout-return URLs the hosted Express onboarding flow bounces
         * the creator back to (useVendorBounce('payout') picks up from
         * there). `webhook_tolerance` is the Stripe signature timestamp
         * tolerance in seconds (replay-window guard).
         */
        'stripe' => [
            'secret_key' => env('STRIPE_SECRET_KEY'),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
            'connect_client_id' => env('STRIPE_CONNECT_CLIENT_ID'),
            'return_url' => env('STRIPE_CONNECT_RETURN_URL', env('APP_FRONTEND_URL', 'http://localhost:5173').'/onboarding/payout/return'),
            'refresh_url' => env('STRIPE_CONNECT_REFRESH_URL', env('APP_FRONTEND_URL', 'http://localhost:5173').'/onboarding/payout/refresh'),
            'webhook_tolerance' => (int) env('STRIPE_WEBHOOK_TOLERANCE', 300),
        ],
    ],

];
