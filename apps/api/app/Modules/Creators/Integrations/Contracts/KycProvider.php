<?php

declare(strict_types=1);

namespace App\Modules\Creators\Integrations\Contracts;

use App\Modules\Creators\Enums\KycStatus;
use App\Modules\Creators\Integrations\DataTransferObjects\KycInitiationResult;
use App\Modules\Creators\Integrations\DataTransferObjects\KycWebhookEvent;
use App\Modules\Creators\Integrations\Stubs\DeferredKycProvider;
use App\Modules\Creators\Models\Creator;

/**
 * Identity-verification provider contract.
 *
 * Sprint 3 Chunk 2 completes the wizard's KYC integration surface
 * (hybrid completion architecture, Decision A = (c) in the chunk-2
 * plan). The contract grew from the Chunk-1 single-method subset
 * to four methods covering the redirect-init, status-poll, and
 * inbound-webhook flows.
 *
 * ## Sprint 3 completion surface (this contract — 4 methods)
 *   - {@see self::initiateVerification()}: kicks off the hosted flow.
 *   - {@see self::getVerificationStatus()}: status-poll for post-redirect UX.
 *   - {@see self::verifyWebhookSignature()}: inbound-webhook signature check.
 *   - {@see self::parseWebhookEvent()}: parse vendor payload to internal DTO.
 *
 * ## Honest deviation D-pause-2-2 — chunk-1 docblock vs chunk-2 surface
 * Chunk 1's docblock named the future-extension methods as
 * `getVerificationResult(string $sessionId): KycResult` and
 * `parseWebhookEvent(string $payload): KycWebhookEvent`. Chunk 2's
 * kickoff replaces `getVerificationResult` with
 * {@see self::getVerificationStatus()} keyed on the {@see Creator}
 * entity rather than the vendor-side `string $sessionId`.
 *
 * Reasoning: in our domain, `Creator` is the durable identity
 * (`creator_kyc_verifications.creator_id` is the FK, multi-attempt
 * history attaches to it, the wizard always has it on the request);
 * the vendor-side session ID is ephemera that may roll over per
 * attempt or expire. Keying the contract on `Creator` keeps the
 * adapter-side responsibility simple ("look up the most recent
 * session for this creator and translate the vendor view") and
 * shields call sites from session-rotation bugs. See
 * docs/reviews/sprint-3-chunk-2-review.md "Honest deviations" for
 * the full reasoning + the tech-debt entry to clean up the chunk-1
 * docblocks once this lands.
 *
 * ## Future-extension methods (Sprint 4+ in real adapter)
 *   - `downloadVerificationDocument(...)`: Sprint 4+ if the admin
 *     review UI needs original document copies; deferred for now.
 *
 * The full surface lives in 06-INTEGRATIONS.md § 3.2 as
 * `IdentityVerificationProviderContract`. Sprint 3 ships the wizard's
 * critical-path subset only.
 *
 * @see DeferredKycProvider
 * @see KycWebhookEvent
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

    /**
     * Poll the provider for the current verification status of the
     * creator's most-recent session.
     *
     * Used by the wizard's status-poll endpoint
     * (`GET /api/v1/creators/me/wizard/kyc/status`) when the creator
     * returns from the vendor's hosted flow, so the UI can display
     * "Verification complete" without waiting for the inbound
     * webhook to fire (the webhook is the authoritative source of
     * truth in production; the status-poll is the UX shortcut).
     *
     * Returns {@see KycStatus} mapped from the vendor's view of the
     * latest session; the handler is responsible for any vendor-
     * specific status-name normalisation.
     */
    public function getVerificationStatus(Creator $creator): KycStatus;

    /**
     * Verify the HMAC (or vendor-equivalent) signature on an inbound
     * webhook payload.
     *
     * Returns true iff the signature is valid for the given payload.
     * The webhook handler returns 401 Unauthorized on false. Single
     * boolean return on purpose — the security envelope must NOT
     * differentiate between "wrong vendor", "stale timestamp", or
     * "malformed HMAC" failure modes; debugging happens via the
     * `processing_error` column on `integration_events`. See
     * docs/reviews/sprint-3-chunk-2-review.md "Decisions documented
     * for future chunks → single error code for webhook signature
     * failures".
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool;

    /**
     * Parse an inbound webhook payload into the internal
     * {@see KycWebhookEvent} DTO. Called only after
     * {@see self::verifyWebhookSignature()} returns true.
     *
     * Throws on malformed payloads (the handler converts to
     * `processing_error` on the integration_events row).
     */
    public function parseWebhookEvent(string $payload): KycWebhookEvent;
}
