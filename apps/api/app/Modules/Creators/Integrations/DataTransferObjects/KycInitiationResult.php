<?php

declare(strict_types=1);

namespace App\Modules\Creators\Integrations\DataTransferObjects;

use App\Modules\Creators\Integrations\Contracts\KycProvider;

/**
 * Result of {@see KycProvider::initiateVerification()}.
 *
 *   - sessionId:      provider-side session identifier (persona/veriff/onfido).
 *   - hostedFlowUrl:  redirect URL for the creator's browser to start the flow.
 *   - expiresAt:      ISO 8601 timestamp when the session URL stops being valid.
 *
 * Stored on `creator_kyc_verifications.provider_session_id` and used by the
 * wizard's KYC step to redirect the creator's browser.
 */
final readonly class KycInitiationResult
{
    public function __construct(
        public string $sessionId,
        public string $hostedFlowUrl,
        public string $expiresAt,
    ) {}
}
