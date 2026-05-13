<?php

declare(strict_types=1);

namespace App\Modules\Creators\Enums;

/**
 * Status of a creator payout method.
 *
 *   pending    Stripe Connect onboarding link generated; awaiting completion.
 *   verified   Stripe reports charges_enabled + payouts_enabled.
 *   restricted Account exists but Stripe needs more information.
 *   disabled   Provider has disabled payouts (compliance / dispute / etc.).
 *
 * Stored as varchar(16) on creator_payout_methods.status. See
 * docs/03-DATA-MODEL.md §5.
 */
enum PayoutStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Restricted = 'restricted';
    case Disabled = 'disabled';
}
