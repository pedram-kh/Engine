<?php

declare(strict_types=1);

namespace App\Modules\Creators\Enums;

/**
 * Per-attempt KYC verification status on `creator_kyc_verifications`.
 * Distinct from {@see KycStatus} which is the denormalised top-level
 * Creator status.
 *
 *   started → pending → passed | failed | expired
 *
 * Stored as varchar(16) on creator_kyc_verifications.status. See
 * docs/03-DATA-MODEL.md §5.
 */
enum KycVerificationStatus: string
{
    case Started = 'started';
    case Pending = 'pending';
    case Passed = 'passed';
    case Failed = 'failed';
    case Expired = 'expired';
}
