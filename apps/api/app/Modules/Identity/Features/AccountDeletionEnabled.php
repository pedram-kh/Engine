<?php

declare(strict_types=1);

namespace App\Modules\Identity\Features;

use Closure;

/**
 * AH-104b-del — the creator's own account-deletion request (the endpoint, the
 * confirmation mail, and the Security page entry that reads it).
 *
 * ONE flag for all three (kickoff D4): the endpoint and the mail are not separately
 * switchable, so there is no state in which a request is accepted without the owner
 * being told. Default OFF; Pedram turns it on in the admin Feature-flags page before
 * build 3 is submitted to App Review (a named step on the build-3 checklist).
 *
 * It gates nothing that already exists: a creator who has ALREADY requested deletion
 * stays pending and can still cancel through sign-in with the flag off, because
 * cancelling must never depend on a switch an operator can flip.
 */
final class AccountDeletionEnabled
{
    public const NAME = 'account_deletion_enabled';

    /** Pennant stores a non-Closure default as-is, so the default MUST be a Closure. */
    public static function default(): Closure
    {
        return static fn (mixed $scope = null): bool => false;
    }
}
