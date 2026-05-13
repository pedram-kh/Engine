<?php

declare(strict_types=1);

namespace App\Modules\Creators\Integrations\Stubs;

use App\Modules\Creators\Integrations\Contracts\KycProvider;
use App\Modules\Creators\Integrations\DataTransferObjects\KycInitiationResult;
use App\Modules\Creators\Integrations\Exceptions\ProviderNotBoundException;
use App\Modules\Creators\Integrations\Mock\MockKycProvider;
use App\Modules\Creators\Models\Creator;

/**
 * Default binding for {@see KycProvider} during Sprint 3 Chunk 1.
 *
 * Sprint 3 Chunk 2 swaps the binding in the Creators module's service
 * provider to the real {@see MockKycProvider}.
 * Until then, calling any contract method explicitly throws so a
 * misconfigured wizard endpoint surfaces clearly rather than silently
 * returning a fake result.
 */
final class DeferredKycProvider implements KycProvider
{
    public function initiateVerification(Creator $creator): KycInitiationResult
    {
        throw ProviderNotBoundException::for('KycProvider', 'initiateVerification');
    }
}
