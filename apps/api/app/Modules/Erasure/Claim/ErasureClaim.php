<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Claim;

/**
 * Proof that an account was claimed for erasure through the gate.
 *
 * `ErasureRun::execute()` takes one of these, so the irreversible steps cannot be reached
 * by a caller that did not pass {@see ErasureClaimer}. The constructor is public only
 * because PHP has no "friend": an architecture spec (`ErasureClaimGuardTest`) fails when
 * anything but the claimer builds one, and when anything but the queued job and the
 * command calls `execute()`.
 *
 * It carries the subject's ulid and how the claim was taken; no name, no email. `$created` is true
 * only for the call that stamped the claim: a second click on a claimed account gets the claim
 * back with `created` false, and the caller does not queue a second run for it.
 */
final readonly class ErasureClaim
{
    public function __construct(
        public string $userUlid,
        public ClaimMode $mode,
        public ?int $operatorId,
        public bool $created = false,
    ) {}
}
