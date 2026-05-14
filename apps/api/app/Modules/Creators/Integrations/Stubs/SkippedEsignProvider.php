<?php

declare(strict_types=1);

namespace App\Modules\Creators\Integrations\Stubs;

use App\Modules\Creators\Enums\EsignStatus;
use App\Modules\Creators\Features\ContractSigningEnabled;
use App\Modules\Creators\Integrations\Contracts\EsignProvider;
use App\Modules\Creators\Integrations\DataTransferObjects\EsignEnvelopeResult;
use App\Modules\Creators\Integrations\DataTransferObjects\EsignWebhookEvent;
use App\Modules\Creators\Integrations\Exceptions\FeatureDisabledException;
use App\Modules\Creators\Models\Creator;

/**
 * Flag-OFF binding for {@see EsignProvider}.
 *
 * Swapped in by {@see CreatorsServiceProvider} (sub-step 8) when
 * `contract_signing_enabled` is OFF — the wizard's contract step
 * routes to the click-through-acceptance fallback instead, writing
 * `creators.click_through_accepted_at` (migration #38, sub-step 7).
 * If any code path bypasses the flag check, this stub surfaces a
 * clear error per #40 / "No silent vendor calls".
 */
final class SkippedEsignProvider implements EsignProvider
{
    public function sendEnvelope(Creator $creator): EsignEnvelopeResult
    {
        throw FeatureDisabledException::for(
            'EsignProvider',
            ContractSigningEnabled::NAME,
            'sendEnvelope',
        );
    }

    public function getEnvelopeStatus(Creator $creator): EsignStatus
    {
        throw FeatureDisabledException::for(
            'EsignProvider',
            ContractSigningEnabled::NAME,
            'getEnvelopeStatus',
        );
    }

    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        throw FeatureDisabledException::for(
            'EsignProvider',
            ContractSigningEnabled::NAME,
            'verifyWebhookSignature',
        );
    }

    public function parseWebhookEvent(string $payload): EsignWebhookEvent
    {
        throw FeatureDisabledException::for(
            'EsignProvider',
            ContractSigningEnabled::NAME,
            'parseWebhookEvent',
        );
    }
}
