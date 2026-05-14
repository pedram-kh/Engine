<?php

declare(strict_types=1);

namespace App\Modules\Creators\Integrations\Stubs;

use App\Modules\Creators\Features\CreatorPayoutMethodEnabled;
use App\Modules\Creators\Integrations\Contracts\PaymentProvider;
use App\Modules\Creators\Integrations\DataTransferObjects\AccountStatus;
use App\Modules\Creators\Integrations\DataTransferObjects\PaymentAccountResult;
use App\Modules\Creators\Integrations\Exceptions\FeatureDisabledException;
use App\Modules\Creators\Models\Creator;

/**
 * Flag-OFF binding for {@see PaymentProvider}.
 *
 * Swapped in by {@see CreatorsServiceProvider} (sub-step 8) when
 * `creator_payout_method_enabled` is OFF — the wizard's payout step
 * is skipped entirely; profile renders "payout setup pending" until
 * an operator flips the flag (which requires Batch 1 §1.1 + Batch 3
 * §3.1 vendor onboarding per docs/SPRINT-0-MANUAL-STEPS.md).
 *
 * If any code path bypasses the flag check, this stub surfaces a
 * clear error per #40 / "No silent vendor calls".
 */
final class SkippedPaymentProvider implements PaymentProvider
{
    public function createConnectedAccount(Creator $creator): PaymentAccountResult
    {
        throw FeatureDisabledException::for(
            'PaymentProvider',
            CreatorPayoutMethodEnabled::NAME,
            'createConnectedAccount',
        );
    }

    public function getAccountStatus(Creator $creator): AccountStatus
    {
        throw FeatureDisabledException::for(
            'PaymentProvider',
            CreatorPayoutMethodEnabled::NAME,
            'getAccountStatus',
        );
    }
}
