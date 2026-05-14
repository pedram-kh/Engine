<?php

declare(strict_types=1);

namespace App\Modules\Creators\Integrations\Stubs;

use App\Modules\Creators\Enums\KycStatus;
use App\Modules\Creators\Integrations\Contracts\KycProvider;
use App\Modules\Creators\Integrations\DataTransferObjects\KycInitiationResult;
use App\Modules\Creators\Integrations\DataTransferObjects\KycWebhookEvent;
use App\Modules\Creators\Integrations\Exceptions\ProviderNotBoundException;
use App\Modules\Creators\Integrations\Mock\MockKycProvider;
use App\Modules\Creators\Models\Creator;

/**
 * Default binding for {@see KycProvider} until the service provider
 * swaps in either {@see MockKycProvider} (flag ON in Sprint 3) or a
 * real adapter (Sprint 4+).
 *
 * Calling any method explicitly throws so a misconfigured wizard
 * endpoint surfaces clearly rather than silently returning a fake
 * result. Distinct from {@see SkippedKycProvider}, which is the
 * flag-OFF binding.
 */
final class DeferredKycProvider implements KycProvider
{
    public function initiateVerification(Creator $creator): KycInitiationResult
    {
        throw ProviderNotBoundException::for('KycProvider', 'initiateVerification');
    }

    public function getVerificationStatus(Creator $creator): KycStatus
    {
        throw ProviderNotBoundException::for('KycProvider', 'getVerificationStatus');
    }

    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        throw ProviderNotBoundException::for('KycProvider', 'verifyWebhookSignature');
    }

    public function parseWebhookEvent(string $payload): KycWebhookEvent
    {
        throw ProviderNotBoundException::for('KycProvider', 'parseWebhookEvent');
    }
}
