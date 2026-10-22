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
 * It carries the subject's ulid and how the claim was taken; no name, no email.
 */
final readonly class ErasureClaim
{
    public function __construct(
        public string $userUlid,
        public ClaimMode $mode,
        public ?int $operatorId,
    ) {}
}
