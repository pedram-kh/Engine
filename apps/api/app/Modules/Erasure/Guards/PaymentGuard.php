<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Guards;

use App\Modules\Erasure\Subject\SubjectSet;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Stage 6: nothing is erased while money is, or may be, owed.
 *
 * Payments are not built yet (Sprint 10), but `payment_held` and
 * `payment_released` already exist as assignment states and `payment_id` as a
 * column. A creator with an assignment in either state, or with a payment id
 * set, has a financial record the platform may have to produce: the run is
 * refused, and the refusal is written here so Sprint 10 cannot make erasure
 * quietly unsafe (a spec fails when a payment table or state appears that this
 * guard has not been told about).
 */
final class PaymentGuard
{
    /**
     * The assignment states that mean money is held or paid. A spec compares
     * this list with every `AssignmentStatus` case whose name contains "payment".
     *
     * @return list<string>
     */
    public static function knownPaymentStates(): array
    {
        return ['payment_held', 'payment_released'];
    }

    /** @return list<string> */
    public function refusals(SubjectSet $subject, ?ConnectionInterface $db = null): array
    {
        $db ??= DB::connection();
        $reasons = [];

        $inState = $db->table('campaign_assignments')->where('creator_id', $subject->creatorId)->whereIn('status', self::knownPaymentStates())->count();
        if ($inState > 0) {
            $reasons[] = sprintf('campaign_assignments: %d assignment(s) in a payment state (%s)', $inState, implode(', ', self::knownPaymentStates()));
        }

        $withPayment = $db->table('campaign_assignments')->where('creator_id', $subject->creatorId)->whereNotNull('payment_id')->count();
        if ($withPayment > 0) {
            $reasons[] = sprintf('campaign_assignments: %d assignment(s) with a payment id', $withPayment);
        }

        return $reasons;
    }
}
