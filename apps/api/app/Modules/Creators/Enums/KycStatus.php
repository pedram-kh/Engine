<?php

declare(strict_types=1);

namespace App\Modules\Creators\Enums;

/**
 * Denormalised KYC status on the Creator row. The full per-attempt
 * history lives in `creator_kyc_verifications` (see KycVerificationStatus
 * for the per-attempt enum).
 *
 *   none → pending → verified | rejected
 *
 * Stored as varchar(16) on creators.kyc_status. See
 * docs/03-DATA-MODEL.md §5.
 */
enum KycStatus: string
{
    case None = 'none';
    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';
}
