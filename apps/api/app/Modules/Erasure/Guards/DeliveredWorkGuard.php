<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Guards;

use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Erasure\Subject\SubjectSet;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * AH-104c: nothing is erased while delivered work is, or may become, payable.
 *
 * An assignment in `live_verified`, `manually_verified` or `completed_on_approval` is work
 * the creator delivered and the agency may owe for. `PaymentGuard` only sees the two
 * states that mean money is already held or paid; between "delivered" and "held" a creator
 * could be erased, their payout method deleted, and the work left unpayable. So these
 * states refuse too (ruling 2026-10-22, Q5 (a); `completed_on_approval` by Question C1).
 *
 * The list is NOT written out: it is the assignment states for which
 * {@see AssignmentStatus::isPaymentEligible()} is true, so a payment-eligible state added
 * later refuses by default. The two states `PaymentGuard` names are refused there, not here.
 * Soft-deleted assignments count, as they do for the payment guard.
 */
final class DeliveredWorkGuard
{
    /** @return list<string> */
    public static function states(): array
    {
        $states = [];
        foreach (AssignmentStatus::cases() as $status) {
            if ($status->isPaymentEligible()) {
                $states[] = $status->value;
            }
        }

        return $states;
    }

    /** @return list<string> */
    public function refusals(SubjectSet $subject, ?ConnectionInterface $db = null): array
    {
        $db ??= DB::connection();

        $delivered = $db->table('campaign_assignments')->where('creator_id', $subject->creatorId)->whereIn('status', self::states())->count();

        return $delivered > 0
            ? [sprintf('campaign_assignments: %d assignment(s) with delivered work (%s)', $delivered, implode(', ', self::states()))]
            : [];
    }
}
