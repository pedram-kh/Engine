<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Erase;

use RuntimeException;

/**
 * The Erase click is refused before anything was claimed: the account is not ready, or cannot be
 * erased at all. `$codes` are coarse, stable codes (never the guards' sentences, which carry
 * counts and table names): a subset of the gate codes (`held`, `not_requested`, `grace_running`),
 * the blocker codes (`payment`, `delivered_work`, `vendor`, `not_erasable`) and, for an account
 * that cannot be erased, `erased` or `not_available`.
 */
final class EraseNotReady extends RuntimeException
{
    public const ERASED = 'erased';

    public const NOT_AVAILABLE = 'not_available';

    /** @param list<string> $codes */
    public function __construct(public readonly array $codes)
    {
        parent::__construct('The account is not ready to be erased: '.implode(', ', $codes));
    }
}
