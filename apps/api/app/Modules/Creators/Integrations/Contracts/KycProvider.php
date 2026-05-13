<?php

declare(strict_types=1);

namespace App\Modules\Creators\Integrations\Contracts;

use App\Modules\Creators\Integrations\DataTransferObjects\KycInitiationResult;
use App\Modules\Creators\Integrations\Stubs\DeferredKycProvider;
use App\Modules\Creators\Models\Creator;

/**
 * Identity-verification provider contract.
 *
 * Sprint 3 Chunk 1 deliberately defines the **subset** of the full
 * {@see https://image.intervention.io 06-INTEGRATIONS.md § 3.2} surface
 * needed by the Creator wizard's KYC step.
 *
 * ## Sprint 3 subset (this contract)
 *   - {@see self::initiateVerification()}: kicks off the hosted flow.
 *
 * ## Future-extension methods (Sprint 4+ via webhook handlers)
 *   - `getVerificationResult(string $sessionId): KycResult`
 *   - `verifyWebhookSignature(string $payload, string $signature): bool`
 *   - `parseWebhookEvent(string $payload): KycWebhookEvent`
 *
 * The full surface lives in 06-INTEGRATIONS.md § 3.2 as
 * `IdentityVerificationProviderContract`. The Sprint-3 contract is
 * narrower because the wizard only initiates the flow; result
 * collection comes through webhooks in Sprint 4.
 *
 * @see DeferredKycProvider
 */
interface KycProvider
{
    /**
     * Start a hosted KYC verification session for the given creator.
     *
     * Returns a session identifier + a URL the creator's browser
     * should be redirected to. Throws on provider-side failure;
     * upstream callers translate to a user-facing error.
     */
    public function initiateVerification(Creator $creator): KycInitiationResult;
}
