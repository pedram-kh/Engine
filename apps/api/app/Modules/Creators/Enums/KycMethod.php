<?php

declare(strict_types=1);

namespace App\Modules\Creators\Enums;

/**
 * Which path cleared a creator's identity verification (D-c3-4/5).
 *
 * Stored as varchar(16) on creators.kyc_method, nullable until identity
 * is cleared. The denormalised discriminator is always populated from
 * whichever path writes kyc_status:
 *
 *   - Vendor: the (mock-now, real-later) KYC webhook path stamps this
 *             whenever ProcessKycWebhookJob writes kyc_status (D-c3-5).
 *   - Manual: the admin verify-identity endpoint stamps this when a
 *             platform_admin clears identity by hand (D-c3-3).
 *
 * The full per-attempt vendor history still lives in
 * creator_kyc_verifications; KycStatus remains the cleared/not-cleared
 * state and KycMethod records how it got there.
 */
enum KycMethod: string
{
    case Vendor = 'vendor';
    case Manual = 'manual';
}
