<?php

declare(strict_types=1);

namespace App\Modules\Creators\Integrations\Contracts;

use App\Modules\Creators\Integrations\DataTransferObjects\EsignEnvelopeResult;
use App\Modules\Creators\Integrations\Stubs\DeferredEsignProvider;
use App\Modules\Creators\Models\Creator;

/**
 * E-signature provider contract.
 *
 * Sprint 3 Chunk 1 deliberately defines the **subset** of the full
 * {@see https://image.intervention.io 06-INTEGRATIONS.md § 4.2} surface
 * needed by the Creator wizard's master-contract step.
 *
 * ## Sprint 3 subset (this contract)
 *   - {@see self::sendEnvelope()}: queues the envelope and returns the
 *     creator-facing signing URL.
 *
 * ## Future-extension methods (Sprint 4+ via webhook handlers)
 *   - `getEnvelopeStatus(string $envelopeId): EnvelopeStatus`
 *   - `downloadSignedDocument(string $envelopeId): SignedDocument`
 *   - `voidEnvelope(string $envelopeId, string $reason): void`
 *   - `verifyWebhookSignature(string $payload, string $signature): bool`
 *   - `parseWebhookEvent(string $payload): EsignWebhookEvent`
 *
 * The full surface lives in 06-INTEGRATIONS.md § 4.2 as
 * `ESignatureProviderContract`. The Sprint-3 contract is narrower
 * because the wizard only sends the envelope; signed-document
 * retrieval and status checks happen via webhooks in Sprint 4.
 *
 * @see DeferredEsignProvider
 */
interface EsignProvider
{
    /**
     * Send the master-services-agreement envelope for the given creator
     * and return the hosted signing URL the creator's browser is
     * redirected to.
     */
    public function sendEnvelope(Creator $creator): EsignEnvelopeResult;
}
