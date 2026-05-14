<?php

declare(strict_types=1);

namespace App\Modules\Creators\Enums;

/**
 * Denormalised KYC status on the Creator row. The full per-attempt
 * history lives in `creator_kyc_verifications` (see KycVerificationStatus
 * for the per-attempt enum).
 *
 *   none → pending → verified | rejected
 *   not_required (Sprint 3 Chunk 2: terminal state when
 *                 kyc_verification_enabled is OFF at submit time)
 *
 * Stored as varchar(16) on creators.kyc_status. See
 * docs/03-DATA-MODEL.md §5.
 *
 * The `NotRequired` case (Q-flag-off-1 = (a) in the chunk-2 plan) is
 * a deliberate forensic-clarity choice: when the wizard's submit
 * happens with the gating flag OFF, the historical record shows
 * "this creator passed without KYC because the operator hadn't
 * enabled the vendor yet" rather than collapsing into the default
 * `None` (which would be ambiguous between "step never started" and
 * "step skipped by flag"). Future-proofs the audit trail when the
 * flag flips ON: existing creators stay `NotRequired` and the
 * admin SPA's KYC review queue can pick them up explicitly rather
 * than re-scanning the full creators table.
 */
enum KycStatus: string
{
    case None = 'none';
    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case NotRequired = 'not_required';
}
