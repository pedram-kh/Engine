<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Exceptions;

use App\Modules\Agencies\Services\AgencyInvitationService;
use RuntimeException;

/**
 * Thrown by {@see AgencyInvitationService::acceptWithNewAccount()}
 * when a `users` row for the invitation's email appears between the
 * controller's pre-check and the transaction's write — the collision
 * race named at AH-095 plan-pause (D3).
 *
 * Two-layer guard, both funnelling into this one exception so the
 * frontend has a single code to branch on:
 *   1. The controller's cheap pre-check (`User::where('email', ...)->exists()`)
 *      catches the common case before opening a transaction at all.
 *   2. The service's `catch` on the `users.email` unique-index violation
 *      catches the rare true-concurrent-submit case that slips past (1) —
 *      never a bare 500.
 *
 * The controller catches this and emits a 409 with `invitation.account_exists`,
 * which the SPA treats identically to `account_exists: true` from the
 * preview endpoint: re-branch to the sign-in path with a clear message.
 */
final class InvitationAccountExistsException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('An account already exists for the invited email.');
    }
}
