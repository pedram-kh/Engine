<?php

declare(strict_types=1);

namespace App\Modules\Creators\Enums;

/**
 * Creator's verification level. P1 uses unverified, email_verified,
 * kyc_verified. tier_verified is reserved for P3.
 *
 * Stored as varchar(16) on creators.verification_level. See
 * docs/03-DATA-MODEL.md §5.
 */
enum VerificationLevel: string
{
    case Unverified = 'unverified';
    case EmailVerified = 'email_verified';
    case KycVerified = 'kyc_verified';
    case TierVerified = 'tier_verified';
}
