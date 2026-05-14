<?php

declare(strict_types=1);

/*
 * Sprint 3 Chunk 2 sub-step 5 — mock-vendor Blade page strings.
 *
 * The mock-vendor pages stand in for real KYC / e-sign / Stripe
 * Connect hosted flows in dev + CI. They exist solely to drive
 * Playwright's redirect-bounce E2E (Chunk 3) and to let a developer
 * manually exercise the wizard end-to-end without a real vendor.
 *
 * Localised in en/pt/it per #3 (Mailable real-render standard,
 * applied by extension to Blade pages here). The strings are
 * intentionally minimal — these are dev pages, not product copy.
 */

return [
    'kyc' => [
        'title' => 'Mock KYC verification',
        'description' => 'You are running against the mock KYC provider. Choose an outcome to simulate.',
        'success' => 'Complete verification (success)',
        'fail' => 'Complete verification (fail)',
        'cancel' => 'Cancel verification',
    ],
    'esign' => [
        'title' => 'Mock e-signature envelope',
        'description' => 'You are running against the mock e-signature provider. Choose an outcome to simulate.',
        'success' => 'Sign envelope',
        'fail' => 'Decline envelope',
        'cancel' => 'Cancel signing',
    ],
    'stripe' => [
        'title' => 'Mock Stripe Connect onboarding',
        'description' => 'You are running against the mock payment provider. Choose an outcome to simulate.',
        'success' => 'Complete onboarding',
        'fail' => 'Cancel onboarding',
    ],
    'session_unknown' => 'Unknown or expired session.',
];
