<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Guards;

use App\Modules\Erasure\Subject\SubjectSet;
use Illuminate\Database\ConnectionInterface;

/**
 * Every reason a subject must not be erased yet, in one list: money held or
 * paid (Stage 6) and a real vendor still holding the person (Stage 4g). Asked
 * once before any object is deleted and again at the top of the database stage,
 * so no caller can reach an irreversible step around it.
 */
final class Refusals
{
    /** @return list<string> */
    public static function for(SubjectSet $subject, ?ConnectionInterface $db = null): array
    {
        return [...(new PaymentGuard)->refusals($subject, $db), ...(new VendorGuard)->refusals($subject, $db)];
    }

    /** @throws ErasureRefused */
    public static function assertNone(SubjectSet $subject, ?ConnectionInterface $db = null): void
    {
        $reasons = self::for($subject, $db);
        if ($reasons !== []) {
            throw new ErasureRefused($reasons);
        }
    }
}
