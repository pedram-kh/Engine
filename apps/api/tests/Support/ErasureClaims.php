<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Erasure\Claim\ClaimMode;
use App\Modules\Erasure\Claim\ErasureClaim;
use App\Modules\Identity\Models\User;

/**
 * AH-104c: the claim `ErasureRun::execute()` now takes, for the specs that exercise the
 * engine itself and not the gate. It stamps `users.erasure_started_at` (the thing the
 * engine re-reads) and hands back the claim value, with no gate decision and no audit row:
 * the gate has its own specs (`ErasureClaimerTest`). Application code never builds a claim
 * this way; an architecture spec fails if anything under `app/` but the claimer does.
 */
final class ErasureClaims
{
    public static function for(User $user): ErasureClaim
    {
        User::query()->withTrashed()->whereKey($user->getKey())->whereNull('erased_at')->update(['erasure_started_at' => now()]);

        return new ErasureClaim($user->ulid, ClaimMode::Normal, null);
    }
}
