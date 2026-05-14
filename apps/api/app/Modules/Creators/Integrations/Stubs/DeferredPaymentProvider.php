<?php

declare(strict_types=1);

namespace App\Modules\Creators\Integrations\Stubs;

use App\Modules\Creators\Integrations\Contracts\PaymentProvider;
use App\Modules\Creators\Integrations\DataTransferObjects\AccountStatus;
use App\Modules\Creators\Integrations\DataTransferObjects\PaymentAccountResult;
use App\Modules\Creators\Integrations\Exceptions\ProviderNotBoundException;
use App\Modules\Creators\Integrations\Mock\MockPaymentProvider;
use App\Modules\Creators\Models\Creator;

/**
 * Default binding for {@see PaymentProvider} until the service
 * provider swaps in either {@see MockPaymentProvider} (flag ON in
 * Sprint 3) or a real Stripe Connect adapter (Sprint 7+ for
 * onboarding; Sprint 10 for escrow flows). Distinct from
 * {@see SkippedPaymentProvider}, which is the flag-OFF binding.
 */
final class DeferredPaymentProvider implements PaymentProvider
{
    public function createConnectedAccount(Creator $creator): PaymentAccountResult
    {
        throw ProviderNotBoundException::for('PaymentProvider', 'createConnectedAccount');
    }

    public function getAccountStatus(Creator $creator): AccountStatus
    {
        throw ProviderNotBoundException::for('PaymentProvider', 'getAccountStatus');
    }
}
