<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Guards;

use App\Modules\Erasure\Registry\ErasureRegistry;

/**
 * The failing-by-default half of Stage 6.
 *
 * Sprint 10 will add payment tables and payment states. This is what stops
 * that from quietly making erasure unsafe: a table with a `payment`, `payout`
 * or `escrow` word in its name must carry an explicit payment policy in the
 * registry, and the assignment states that mean "money held or paid" must be
 * the ones {@see PaymentGuard} refuses on. Anything new fails CI until a person
 * decides what erasure does about it.
 */
final class PaymentPolicy
{
    /** Name segments that mark a table as financial. */
    private const array SEGMENTS = ['payment', 'payments', 'payout', 'payouts', 'escrow', 'escrows'];

    /**
     * @param  list<string>  $tables  every table in the schema
     * @param  list<string>  $assignmentStatuses  every AssignmentStatus value
     * @return list<string>
     */
    public static function violations(array $tables, array $assignmentStatuses): array
    {
        $policies = ErasureRegistry::paymentPolicies();
        $problems = [];

        foreach ($tables as $table) {
            if (self::looksFinancial($table) && ! isset($policies[$table])) {
                $problems[] = "Table `{$table}` looks financial (payment, payout or escrow in its name) but has no payment policy: add it to ErasureRegistry::paymentPolicies() and decide what erasure does about it.";
            }
        }

        foreach (array_keys($policies) as $table) {
            if (! in_array($table, $tables, true)) {
                $problems[] = "Payment policy for `{$table}` is stale: the table no longer exists. Remove it from ErasureRegistry::paymentPolicies().";
            }
        }

        $inSchema = array_values(array_filter($assignmentStatuses, fn (string $s): bool => str_contains($s, 'payment')));
        $known = PaymentGuard::knownPaymentStates();
        sort($inSchema);
        sort($known);
        if ($inSchema !== $known) {
            $problems[] = 'The assignment states that mention payment ('.implode(', ', $inSchema).') differ from PaymentGuard::knownPaymentStates() ('.implode(', ', $known).'): update the guard so erasure still refuses while money is held or paid.';
        }

        return $problems;
    }

    private static function looksFinancial(string $table): bool
    {
        return array_intersect(explode('_', $table), self::SEGMENTS) !== [];
    }
}
