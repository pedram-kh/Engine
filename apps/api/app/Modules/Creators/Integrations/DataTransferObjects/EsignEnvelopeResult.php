<?php

declare(strict_types=1);

namespace App\Modules\Creators\Integrations\DataTransferObjects;

use App\Modules\Creators\Integrations\Contracts\EsignProvider;
use App\Modules\Creators\Resources\CreatorResource;

/**
 * Result of {@see EsignProvider::sendEnvelope()}.
 *
 *   - envelopeId:     provider-side envelope identifier (DocuSign-style).
 *   - signingUrl:     hosted signing flow URL for the creator's browser.
 *   - expiresAt:      ISO 8601 timestamp when the signing URL stops being valid.
 *
 * Stored on the wizard's master-contract step and surfaced via
 * {@see CreatorResource}.
 */
final readonly class EsignEnvelopeResult
{
    public function __construct(
        public string $envelopeId,
        public string $signingUrl,
        public string $expiresAt,
    ) {}
}
