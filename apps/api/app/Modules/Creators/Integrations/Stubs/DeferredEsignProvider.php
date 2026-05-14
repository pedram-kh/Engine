<?php

declare(strict_types=1);

namespace App\Modules\Creators\Integrations\Stubs;

use App\Modules\Creators\Enums\EsignStatus;
use App\Modules\Creators\Integrations\Contracts\EsignProvider;
use App\Modules\Creators\Integrations\DataTransferObjects\EsignEnvelopeResult;
use App\Modules\Creators\Integrations\DataTransferObjects\EsignWebhookEvent;
use App\Modules\Creators\Integrations\Exceptions\ProviderNotBoundException;
use App\Modules\Creators\Integrations\Mock\MockEsignProvider;
use App\Modules\Creators\Models\Creator;

/**
 * Default binding for {@see EsignProvider} until the service provider
 * swaps in either {@see MockEsignProvider} (flag ON in Sprint 3) or
 * a real adapter (Sprint 9). Distinct from {@see SkippedEsignProvider},
 * which is the flag-OFF binding.
 */
final class DeferredEsignProvider implements EsignProvider
{
    public function sendEnvelope(Creator $creator): EsignEnvelopeResult
    {
        throw ProviderNotBoundException::for('EsignProvider', 'sendEnvelope');
    }

    public function getEnvelopeStatus(Creator $creator): EsignStatus
    {
        throw ProviderNotBoundException::for('EsignProvider', 'getEnvelopeStatus');
    }

    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        throw ProviderNotBoundException::for('EsignProvider', 'verifyWebhookSignature');
    }

    public function parseWebhookEvent(string $payload): EsignWebhookEvent
    {
        throw ProviderNotBoundException::for('EsignProvider', 'parseWebhookEvent');
    }
}
