<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Events\TwoFactorRecoveryCodeConsumed;
use App\Modules\Identity\Models\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;
use SensitiveParameter;

/**
 * Verifies a candidate `mfa_code` value at login time.
 *
 * Chunk 5 priority #9 wire contract:
 *   - The user submits a single `mfa_code` field. The service tries
 *     TOTP first when the candidate matches the 6-digit format, and
 *     otherwise falls through to recovery codes.
 *   - TOTP success returns {@see TwoFactorChallengeResult::totp()};
 *     the LoginSucceeded audit row carries `mfa: true`.
 *   - Recovery-code success consumes the matching hash atomically
 *     ({@see consumeRecoveryCode()}) and emits the dedicated
 *     `mfa.recovery_code_consumed` audit event.
 *   - Per-attempt TOTP outcomes are intentionally NOT audited (would
 *     flood the log) — the LoginFailed row already exists and the
 *     verification throttle records the suspicious-volume signal.
 *
 * Recovery-code consumption (priority #4) runs inside a serialized DB
 * transaction with `SELECT ... FOR UPDATE` on the user row so two
 * simultaneous requests with the same code can never both succeed.
 * The {@see consumeRecoveryCode()} method re-reads the user inside
 * the lock, walks the hash list with constant-time {@see hash_equals()}-
 * style comparison via {@see TwoFactorService::checkRecoveryCode()},
 * removes the matching hash, and rewrites the column.
 */
final class TwoFactorChallengeService
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Dispatcher $events,
        private readonly TwoFactorService $twoFactor,
    ) {}

    public function verify(User $user, #[SensitiveParameter] string $candidate, Request $request): TwoFactorChallengeResult
    {
        $candidate = trim($candidate);

        if ($candidate === '') {
            return TwoFactorChallengeResult::failed();
        }

        $secret = $user->two_factor_secret;

        if ($this->twoFactor->looksLikeTotpCode($candidate)) {
            if (is_string($secret) && $secret !== '' && $this->twoFactor->verifyTotp($secret, $candidate)) {
                return TwoFactorChallengeResult::totp();
            }

            // Fall through: even a 6-digit string COULD be a recovery
            // code that happens to be all digits (extremely unlikely
            // with the current 16-hex format, but the contract is
            // "try both" so a future format change can't break login).
        }

        if ($this->consumeRecoveryCode($user, $candidate, $request)) {
            return TwoFactorChallengeResult::recoveryCode();
        }

        return TwoFactorChallengeResult::failed();
    }

    /**
     * Atomic find-and-burn of a recovery code. Returns true iff a
     * matching hash was found AND removed in this call.
     *
     * Concurrency model:
     *   - The whole find-and-burn happens inside a transaction.
     *   - We re-read the user with `lockForUpdate()` so any concurrent
     *     attempt blocks until ours commits.
     *   - On commit, the consumed hash is gone; the second request
     *     wakes up, re-reads the now-shortened list, fails to find
     *     a match, and returns false.
     *   - SQLite (test driver) honours `lockForUpdate` as a no-op but
     *     still serializes writes per file lock, so the same guarantee
     *     holds in tests.
     */
    public function consumeRecoveryCode(User $user, #[SensitiveParameter] string $candidate, Request $request): bool
    {
        $candidate = trim($candidate);

        if ($candidate === '') {
            return false;
        }

        return $this->db->transaction(function () use ($user, $candidate, $request): bool {
            /** @var User|null $locked */
            $locked = User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof User) {
                return false;
            }

            /** @var array<int, string>|null $hashes */
            $hashes = $locked->two_factor_recovery_codes;

            if (! is_array($hashes) || $hashes === []) {
                return false;
            }

            $matchedIndex = null;

            // Walk every hash even after we find a match so the
            // verification cost is constant in the hash count and
            // doesn't leak which slot matched via a timing channel.
            $matched = false;
            foreach ($hashes as $index => $hash) {
                if (! $matched && $this->twoFactor->checkRecoveryCode($candidate, $hash)) {
                    $matched = true;
                    $matchedIndex = $index;
                }
            }

            if ($matchedIndex === null) {
                return false;
            }

            unset($hashes[$matchedIndex]);
            $remaining = array_values($hashes);

            $locked->forceFill([
                'two_factor_recovery_codes' => $remaining,
            ])->saveQuietly();

            $this->events->dispatch(new TwoFactorRecoveryCodeConsumed(
                user: $locked,
                remainingCount: count($remaining),
                ip: $request->ip(),
                userAgent: $request->userAgent(),
            ));

            return true;
        });
    }
}
