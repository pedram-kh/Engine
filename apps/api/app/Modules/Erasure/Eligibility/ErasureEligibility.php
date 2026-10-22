<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Eligibility;

use App\Modules\Erasure\Guards\DeliveredWorkGuard;
use App\Modules\Erasure\Guards\PaymentGuard;
use App\Modules\Erasure\Guards\VendorGuard;
use App\Modules\Erasure\Subject\SubjectNotErasable;
use App\Modules\Erasure\Subject\SubjectSet;
use App\Modules\Identity\Models\User;

/**
 * The one public question other modules may ask the erasure engine: "is
 * anything standing in the way of erasing this person?" (AH-104b-del A3).
 *
 * It is a read-only facade over the same guards the engine itself asks at the
 * top of a run (`Refusals`), so a request that is accepted now cannot be
 * refused later for a reason this call could have named, except by something
 * that changed in between (the engine asks again at erasure time, always).
 *
 * The answer is a short list of coarse, stable codes, never the guards' own
 * sentences: those carry counts and table names that mean nothing to a person
 * and must not leave the server.
 */
final class ErasureEligibility
{
    public const PAYMENT = 'payment';

    public const VENDOR = 'vendor';

    /** AH-104c: the creator has delivered work (verified, or completed on approval) that may be payable. */
    public const DELIVERED_WORK = 'delivered_work';

    /**
     * @return list<string> empty when nothing blocks; else a subset of
     *                      {@see PAYMENT}, {@see DELIVERED_WORK}, {@see VENDOR}
     *
     * @throws SubjectNotErasable when the account is not an erasable creator
     */
    public function blockers(User $user): array
    {
        $subject = SubjectSet::resolve($user);

        $blockers = [];
        if ((new PaymentGuard)->refusals($subject) !== []) {
            $blockers[] = self::PAYMENT;
        }
        if ((new DeliveredWorkGuard)->refusals($subject) !== []) {
            $blockers[] = self::DELIVERED_WORK;
        }
        if ((new VendorGuard)->refusals($subject) !== []) {
            $blockers[] = self::VENDOR;
        }

        return $blockers;
    }
}
