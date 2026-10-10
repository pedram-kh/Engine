<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Push;

use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Models\UserPushToken;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The single owner of `user_push_tokens` reads and writes (AH-102, plan §3.2–3.3).
 *
 * Everything that asks "who can receive a push" goes through
 * {@see self::liveTokensFor()} — the liveness rules live in exactly one query.
 */
final class PushTokenRegistry
{
    /**
     * Register (or refresh) a device token for the bearer it arrived under.
     *
     * Two uniqueness rules, applied in one transaction:
     *  - by TOKEN VALUE — the same device token under another account/bearer
     *    MOVES: the stale row is removed, so a handset that changes hands is
     *    never addressable by two logins;
     *  - by BEARER — one row per personal access token, so an FCM token rotation
     *    on the same login REPLACES the stored token instead of accumulating.
     *
     * Registering always clears any prior invalidation: a token the client just
     * handed us is, by definition, the freshest evidence it is alive.
     */
    public function register(
        User $user,
        PersonalAccessToken $bearer,
        PushPlatform $platform,
        string $token,
        ?string $appVersion = null,
    ): UserPushToken {
        try {
            return $this->upsert($user, $bearer, $platform, $token, $appVersion);
        } catch (UniqueConstraintViolationException) {
            // Two registrations for the same device raced past the pre-delete.
            // The loser retries once against the winner's committed row.
            return $this->upsert($user, $bearer, $platform, $token, $appVersion);
        }
    }

    /**
     * The tokens a push may be sent to for this user — the ONE liveness query.
     *
     * A row is live only when ALL hold:
     *  - it has not been invalidated by the provider;
     *  - its bearer still exists (the FK cascade covers revocation) AND would
     *    still authenticate, by Sanctum's own use-time rule: unexpired by
     *    `created_at + sanctum.expiration` (when set) and `expires_at` null or
     *    in the future;
     *  - the owning user is not soft-deleted (`SoftDeletes` does not trigger the
     *    FK cascade).
     *
     * @return Collection<int, UserPushToken>
     */
    public function liveTokensFor(User $user): Collection
    {
        $query = UserPushToken::query()
            ->select('user_push_tokens.*')
            ->join('personal_access_tokens', 'personal_access_tokens.id', '=', 'user_push_tokens.personal_access_token_id')
            ->join('users', 'users.id', '=', 'user_push_tokens.user_id')
            ->where('user_push_tokens.user_id', $user->getKey())
            ->whereNull('user_push_tokens.invalidated_at')
            ->whereNull('users.deleted_at')
            ->where(function ($q): void {
                $q->whereNull('personal_access_tokens.expires_at')
                    ->orWhere('personal_access_tokens.expires_at', '>', now());
            });

        $expirationMinutes = (int) config('sanctum.expiration');

        if ($expirationMinutes > 0) {
            $query->where('personal_access_tokens.created_at', '>', now()->subMinutes($expirationMinutes));
        }

        return $query->orderBy('user_push_tokens.id')->get();
    }

    /**
     * Record a provider-reported dead token. The row is kept (so the reason is
     * auditable) but drops out of {@see self::liveTokensFor()}.
     */
    public function invalidate(UserPushToken $token, string $reason): void
    {
        $token->forceFill([
            'invalidated_at' => now(),
            'invalidation_reason' => mb_substr($reason, 0, 64),
        ])->save();
    }

    /**
     * Delete every device token the user owns; returns the number removed.
     *
     * AH-104 SEAM — account deletion calls this. It is built here because it is
     * the registry's own delete path, and is deliberately NOT wired into any
     * flow yet.
     */
    public function revokeAllForUser(User $user): int
    {
        return UserPushToken::query()->where('user_id', $user->getKey())->delete();
    }

    private function upsert(
        User $user,
        PersonalAccessToken $bearer,
        PushPlatform $platform,
        string $token,
        ?string $appVersion,
    ): UserPushToken {
        return DB::transaction(function () use ($user, $bearer, $platform, $token, $appVersion): UserPushToken {
            UserPushToken::query()
                ->where('token', $token)
                ->where('personal_access_token_id', '!=', $bearer->getKey())
                ->delete();

            return UserPushToken::query()->updateOrCreate(
                ['personal_access_token_id' => $bearer->getKey()],
                [
                    'user_id' => $user->getKey(),
                    'platform' => $platform,
                    'token' => $token,
                    'app_version' => $appVersion,
                    'last_seen_at' => now(),
                    'invalidated_at' => null,
                    'invalidation_reason' => null,
                ],
            );
        });
    }
}
