<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;

/**
 * The "is this app sign-in still live?" predicate, in exactly one place
 * (AH-102b plan §3.3, extracted from `PushTokenRegistry::liveQuery()`).
 *
 * A personal access token (PAT) is a live sign-in only when ALL hold:
 *  - its owning user is not soft-deleted (`SoftDeletes` does not fire the
 *    FK cascade that removes a revoked bearer's rows);
 *  - `expires_at` is null or in the future;
 *  - when `sanctum.expiration` is set (> 0 minutes), the token was created
 *    within that window — Sanctum's own use-time rule (`created_at +
 *    expiration`), which the guard enforces per request even though the
 *    row still exists.
 *
 * This deliberately does NOT filter by token name (`creator-app:%`) and does
 * NOT know about push tokens: callers add their own constraints on top
 * (push adds `invalidated_at IS NULL`; the admin creator-app usage list adds
 * the name prefix). Everything that asks "is this sign-in live?" — delivery
 * eligibility and the admin usage views — goes through {@see self::constrain()},
 * so the two can never drift apart.
 */
final class LiveAppSession
{
    /**
     * Constrain a query that already has the PAT and users tables in scope
     * (joined, or referenced from a correlated subquery) to live sign-ins.
     *
     * @template TBuilder of BuilderContract
     *
     * @param  TBuilder  $query
     * @param  string  $pat  the PAT table name or alias in `$query`
     * @param  string  $users  the users table name or alias in `$query`
     * @return TBuilder
     */
    public static function constrain(
        BuilderContract $query,
        string $pat = 'personal_access_tokens',
        string $users = 'users',
    ): BuilderContract {
        $query
            ->whereNull($users.'.deleted_at')
            ->where(function ($q) use ($pat): void {
                $q->whereNull($pat.'.expires_at')
                    ->orWhere($pat.'.expires_at', '>', now());
            });

        $expirationMinutes = (int) config('sanctum.expiration');

        if ($expirationMinutes > 0) {
            $query->where($pat.'.created_at', '>', now()->subMinutes($expirationMinutes));
        }

        return $query;
    }
}
