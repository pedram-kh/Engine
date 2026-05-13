<?php

declare(strict_types=1);

namespace App\Modules\Creators\Integrations\Stubs;

use App\Modules\Creators\Integrations\Contracts\PaymentProvider;
use App\Modules\Creators\Integrations\DataTransferObjects\PaymentAccountResult;
use App\Modules\Creators\Integrations\Exceptions\ProviderNotBoundException;
use App\Modules\Creators\Integrations\Mock\MockPaymentProvider;
use App\Modules\Creators\Models\Creator;

/**
 * Default binding for {@see PaymentProvider} during Sprint 3 Chunk 1.
 *
 * Sprint 3 Chunk 2 swaps the binding to the real
 * {@see MockPaymentProvider}.
 * Until then, calling any contract method explicitly throws so a
 * misconfigured wizard endpoint surfaces clearly rather than silently
 * returning a fake connected account.
 */
final class DeferredPaymentProvider implements PaymentProvider
{
    public function createConnectedAccount(Creator $creator): PaymentAccountResult
    {
        throw ProviderNotBoundException::for('PaymentProvider', 'createConnectedAccount');
    }
}
