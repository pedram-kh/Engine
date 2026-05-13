<?php

declare(strict_types=1);

namespace App\Modules\Creators\Integrations\Stubs;

use App\Modules\Creators\Integrations\Contracts\EsignProvider;
use App\Modules\Creators\Integrations\DataTransferObjects\EsignEnvelopeResult;
use App\Modules\Creators\Integrations\Exceptions\ProviderNotBoundException;
use App\Modules\Creators\Integrations\Mock\MockEsignProvider;
use App\Modules\Creators\Models\Creator;

/**
 * Default binding for {@see EsignProvider} during Sprint 3 Chunk 1.
 *
 * Sprint 3 Chunk 2 swaps the binding to the real
 * {@see MockEsignProvider}.
 * Until then, calling any contract method explicitly throws so a
 * misconfigured wizard endpoint surfaces clearly rather than silently
 * returning a fake envelope.
 */
final class DeferredEsignProvider implements EsignProvider
{
    public function sendEnvelope(Creator $creator): EsignEnvelopeResult
    {
        throw ProviderNotBoundException::for('EsignProvider', 'sendEnvelope');
    }
}
