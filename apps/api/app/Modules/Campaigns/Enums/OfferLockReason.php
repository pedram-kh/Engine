<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Enums;

use App\Modules\Campaigns\Services\AssignmentOfferLock;

/**
 * Why an assignment's offer amount cannot be edited (AH-118 item 2). The wire
 * values are API contract: the SPA maps each to a sentence and never re-derives
 * the rule. See {@see AssignmentOfferLock} for
 * the order they are evaluated in.
 */
enum OfferLockReason: string
{
    /** `assignment_offer_edit_enabled` is OFF. */
    case FeatureDisabled = 'feature_disabled';

    /** Payment has started (a `payment_id`, or a payment_held/released status). */
    case Payment = 'payment';

    /** The creator has signed the per-campaign contract. */
    case ContractSigned = 'contract_signed';

    /** A contract has been sent and awaits the creator's signature (Q1). */
    case ContractPending = 'contract_pending';

    /** The status is past the editable window (anything but invited/accepted). */
    case Status = 'status';
}
