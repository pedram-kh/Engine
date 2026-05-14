<?php

declare(strict_types=1);

namespace App\Modules\Creators\Integrations\Contracts;

use App\Modules\Creators\Enums\EsignStatus;
use App\Modules\Creators\Integrations\DataTransferObjects\EsignEnvelopeResult;
use App\Modules\Creators\Integrations\DataTransferObjects\EsignWebhookEvent;
use App\Modules\Creators\Integrations\Stubs\DeferredEsignProvider;
use App\Modules\Creators\Models\Creator;

/**
 * E-signature provider contract.
 *
 * Sprint 3 Chunk 2 completes the wizard's master-contract integration
 * surface (hybrid completion architecture, Decision A = (c) in the
 * chunk-2 plan). The contract grew from the Chunk-1 single-method
 * subset to four methods covering envelope-send, status-poll, and
 * inbound-webhook flows.
 *
 * ## Sprint 3 completion surface (this contract — 4 methods)
 *   - {@see self::sendEnvelope()}: queues envelope; returns signing URL.
 *   - {@see self::getEnvelopeStatus()}: status-poll for post-redirect UX.
 *   - {@see self::verifyWebhookSignature()}: inbound-webhook signature check.
 *   - {@see self::parseWebhookEvent()}: parse vendor payload to internal DTO.
 *
 * ## Honest deviation D-pause-2-2 — chunk-1 docblock vs chunk-2 surface
 * Chunk 1's docblock named the future-extension method as
 * `getEnvelopeStatus(string $envelopeId): EnvelopeStatus`. Chunk 2's
 * kickoff replaces it with
 * {@see self::getEnvelopeStatus()} keyed on {@see Creator} returning
 * {@see EsignStatus}.
 *
 * Reasoning identical to the KycProvider analogue: in our domain,
 * `Creator` is the durable identity. The wizard tracks the envelope
 * via `creators.signed_master_contract_id` (and click-through
 * acceptance via `creators.click_through_accepted_at` once
 * migration #38 lands in sub-step 7). Vendor-side envelope IDs are
 * ephemera per attempt. Keying the contract on `Creator` keeps the
 * adapter responsible for "find the latest envelope for this
 * creator and translate the vendor view" and shields call sites
 * from envelope-rotation bugs. See
 * docs/reviews/sprint-3-chunk-2-review.md "Honest deviations" for
 * the full reasoning.
 *
 * ## Future-extension methods (Sprint 9+ in real adapter)
 *   - `downloadSignedDocument(Creator $creator): SignedDocument`
 *   - `voidEnvelope(Creator $creator, string $reason): void`
 *
 * The full surface lives in 06-INTEGRATIONS.md § 4.2 as
 * `ESignatureProviderContract`. Sprint 3 ships the wizard's
 * critical-path subset only.
 *
 * @see DeferredEsignProvider
 * @see EsignWebhookEvent
 */
interface EsignProvider
{
    /**
     * Send the master-services-agreement envelope for the given creator
     * and return the hosted signing URL the creator's browser is
     * redirected to.
     */
    public function sendEnvelope(Creator $creator): EsignEnvelopeResult;

    /**
     * Poll the provider for the current envelope status of the
     * creator's most-recent envelope.
     *
     * Mirrors the KYC status-poll pattern: used by the wizard's
     * status-poll endpoint when the creator returns from the
     * vendor's hosted signing flow. The webhook is the
     * authoritative source of truth; this is the UX shortcut.
     */
    public function getEnvelopeStatus(Creator $creator): EsignStatus;

    /**
     * Verify the HMAC (or vendor-equivalent) signature on an inbound
     * webhook payload. Single-boolean return — the security envelope
     * MUST NOT differentiate between failure modes (see
     * KycProvider::verifyWebhookSignature() for the binding decision).
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool;

    /**
     * Parse an inbound webhook payload into the internal
     * {@see EsignWebhookEvent} DTO. Called only after
     * {@see self::verifyWebhookSignature()} returns true. Throws on
     * malformed payloads.
     */
    public function parseWebhookEvent(string $payload): EsignWebhookEvent;
}
