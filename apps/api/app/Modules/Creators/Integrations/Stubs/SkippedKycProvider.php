<?php

declare(strict_types=1);

namespace App\Modules\Creators\Integrations\Stubs;

use App\Modules\Creators\Enums\KycStatus;
use App\Modules\Creators\Features\KycVerificationEnabled;
use App\Modules\Creators\Integrations\Contracts\KycProvider;
use App\Modules\Creators\Integrations\DataTransferObjects\KycInitiationResult;
use App\Modules\Creators\Integrations\DataTransferObjects\KycWebhookEvent;
use App\Modules\Creators\Integrations\Exceptions\FeatureDisabledException;
use App\Modules\Creators\Models\Creator;

/**
 * Flag-OFF binding for {@see KycProvider}.
 *
 * {@see CreatorsServiceProvider} swaps the binding to this stub when
 * `kyc_verification_enabled` is OFF (sub-step 8). Wizard endpoints
 * gating the KYC step on the flag check the flag BEFORE invoking
 * the provider; this stub is the defence-in-depth backstop that
 * surfaces a clear error if any code path bypasses the flag check
 * (#40 + docs/feature-flags.md "No silent vendor calls").
 *
 * Distinct from {@see DeferredKycProvider}, which is the no-binding
 * fallback before any wiring is in place at all.
 */
final class SkippedKycProvider implements KycProvider
{
    public function initiateVerification(Creator $creator): KycInitiationResult
    {
        throw FeatureDisabledException::for(
            'KycProvider',
            KycVerificationEnabled::NAME,
            'initiateVerification',
        );
    }

    public function getVerificationStatus(Creator $creator): KycStatus
    {
        throw FeatureDisabledException::for(
            'KycProvider',
            KycVerificationEnabled::NAME,
            'getVerificationStatus',
        );
    }

    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        throw FeatureDisabledException::for(
            'KycProvider',
            KycVerificationEnabled::NAME,
            'verifyWebhookSignature',
        );
    }

    public function parseWebhookEvent(string $payload): KycWebhookEvent
    {
        throw FeatureDisabledException::for(
            'KycProvider',
            KycVerificationEnabled::NAME,
            'parseWebhookEvent',
        );
    }
}
