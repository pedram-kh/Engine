<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Services;

use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Enums\OfferLockReason;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Creators\Enums\ContractStatus;
use App\Modules\Creators\Features\AssignmentOfferEditEnabled;
use App\Modules\Creators\Models\Contract;
use Laravel\Pennant\Feature;

/**
 * THE rule for "can the agency still edit this assignment's offer amount?"
 * (AH-118 item 2). One source: the editor re-runs it under a row lock, and the
 * agency payloads serialise its answer, so the SPA never re-derives it.
 *
 * Checked in this order; the first hit is the reason reported:
 *
 *   1. feature_disabled  — `assignment_offer_edit_enabled` is OFF.
 *   2. payment           — `payment_id` set, or status payment_held/released.
 *   3. contract_signed   — `contract_id` set (it is only ever set in the
 *                          transaction in which the creator signs).
 *   4. contract_pending  — a Contract row in `sent` status (Q1).
 *   5. status            — not `invited` / `accepted`. This covers `countered`
 *                          and `declined` (Q4: re-invite / re-offer instead),
 *                          `contracted` for a contract-less campaign (Q3), the
 *                          payment-eligible states (Q2) and every later or
 *                          terminal state.
 *
 * `null` means editable.
 */
final class AssignmentOfferLock
{
    public function evaluate(CampaignAssignment $assignment): ?OfferLockReason
    {
        if (! Feature::active(AssignmentOfferEditEnabled::NAME)) {
            return OfferLockReason::FeatureDisabled;
        }

        if ($assignment->payment_id !== null
            || in_array($assignment->status, [AssignmentStatus::PaymentHeld, AssignmentStatus::PaymentReleased], true)) {
            return OfferLockReason::Payment;
        }

        if ($assignment->contract_id !== null) {
            return OfferLockReason::ContractSigned;
        }

        if ($this->hasSentContract($assignment)) {
            return OfferLockReason::ContractPending;
        }

        if (! in_array($assignment->status, [AssignmentStatus::Invited, AssignmentStatus::Accepted], true)) {
            return OfferLockReason::Status;
        }

        return null;
    }

    /**
     * Reads the eager-loaded `sentContract` when the caller has it (the list
     * endpoint), and asks the database otherwise (the editor, which must see
     * the committed truth, so it never pre-loads).
     */
    private function hasSentContract(CampaignAssignment $assignment): bool
    {
        if ($assignment->relationLoaded('sentContract')) {
            return $assignment->sentContract !== null;
        }

        return Contract::query()
            ->where('subject_type', Contract::SUBJECT_CAMPAIGN_ASSIGNMENT)
            ->where('subject_id', $assignment->id)
            ->where('status', ContractStatus::Sent)
            ->exists();
    }
}
