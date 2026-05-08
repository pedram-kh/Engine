<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Events\TwoFactorConfirmed;
use App\Modules\Identity\Events\TwoFactorDisabled;
use App\Modules\Identity\Events\TwoFactorEnabled;
use App\Modules\Identity\Events\TwoFactorRecoveryCodesRegenerated;
use App\Modules\Identity\Models\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Owns the enable / confirm / disable / regenerate-codes flow.
 *
 * Two-step enrollment (chunk 5 priority #8):
 *
 *   1. {@see start()} mints a fresh secret, builds the otpauth URL +
 *      QR SVG, and writes the secret + a random provisional token to
 *      cache under a per-user key. The token is what the SPA echoes
 *      back on /confirm — without it, the secret is unreachable. The
 *      `users` row is NOT mutated; an abandoned enrollment evaporates
 *      when the cache TTL elapses (10 minutes by default).
 *
 *   2. {@see confirm()} fetches the cached secret, verifies the user's
 *      first TOTP code against it, and only then persists the secret +
 *      generates + hashes recovery codes + stamps
 *      `two_factor_confirmed_at` — all in the same DB transaction.
 *      The plaintext recovery codes are returned ONCE to the caller
 *      and are not retrievable thereafter.
 *
 * Disable (priority #5 + #10): {@see disable()} runs inside a single
 * transaction that nulls `two_factor_secret`,
 * `two_factor_recovery_codes`, AND `two_factor_confirmed_at` together
 * — partial state would let an attacker leave a wiped secret with a
 * stale `confirmed_at` and slip past the MFA gate. The controller is
 * responsible for verifying the user's current password AND a working
 * 2FA code before calling this method.
 *
 * Regenerate recovery codes (priority #12): {@see regenerateRecoveryCodes()}
 * builds a new batch, hashes them, replaces the column, and emits the
 * audit event. The plaintext returned is shown to the user once.
 */
final class TwoFactorEnrollmentService
{
    private const CACHE_PREFIX = 'identity:2fa:enroll:';

    private const CACHE_TTL_SECONDS = 600; // 10 minutes

    public function __construct(
        private readonly Cache $cache,
        private readonly Config $config,
        private readonly ConnectionInterface $db,
        private readonly Dispatcher $events,
        private readonly TwoFactorService $twoFactor,
    ) {}

    public function start(User $user, Request $request): TwoFactorEnrollmentResult
    {
        $secret = $this->twoFactor->generateSecret();
        $token = (string) Str::ulid();

        $this->cache->put(
            $this->cacheKey($user, $token),
            $secret,
            self::CACHE_TTL_SECONDS,
        );

        $issuer = (string) ($this->config->get('app.name') ?? 'Catalyst');
        $otpauthUrl = $this->twoFactor->otpauthUrl($issuer, $user->email, $secret);

        $this->events->dispatch(new TwoFactorEnabled(
            user: $user,
            ip: $request->ip(),
            userAgent: $request->userAgent(),
        ));

        return new TwoFactorEnrollmentResult(
            provisionalToken: $token,
            otpauthUrl: $otpauthUrl,
            qrCodeSvg: $this->twoFactor->qrCodeSvg($otpauthUrl),
            manualEntryKey: $secret,
            expiresInSeconds: self::CACHE_TTL_SECONDS,
        );
    }

    public function confirm(
        User $user,
        string $provisionalToken,
        #[SensitiveParameter] string $code,
        Request $request,
    ): TwoFactorConfirmationResult {
        if ($user->hasTwoFactorEnabled()) {
            return TwoFactorConfirmationResult::alreadyConfirmed();
        }

        $key = $this->cacheKey($user, $provisionalToken);
        /** @var string|null $secret */
        $secret = $this->cache->get($key);

        if (! is_string($secret) || $secret === '') {
            return TwoFactorConfirmationResult::provisionalNotFound();
        }

        if (! $this->twoFactor->verifyTotp($secret, $code)) {
            return TwoFactorConfirmationResult::invalidCode();
        }

        $plainCodes = $this->twoFactor->generateRecoveryCodes();
        $hashedCodes = array_map(
            fn (string $plain): string => $this->twoFactor->hashRecoveryCode($plain),
            $plainCodes,
        );

        $this->db->transaction(function () use ($user, $secret, $hashedCodes): void {
            $user->forceFill([
                'two_factor_secret' => $secret,
                'two_factor_recovery_codes' => array_values($hashedCodes),
                'two_factor_confirmed_at' => Carbon::now(),
                'two_factor_enrollment_suspended_at' => null,
            ])->saveQuietly();
        });

        $this->cache->forget($key);

        $this->events->dispatch(new TwoFactorConfirmed(
            user: $user->refresh(),
            ip: $request->ip(),
            userAgent: $request->userAgent(),
        ));

        return TwoFactorConfirmationResult::confirmed($plainCodes);
    }

    public function disable(User $user, Request $request): void
    {
        if (! $user->hasTwoFactorEnabled()) {
            return;
        }

        $this->db->transaction(function () use ($user): void {
            $user->forceFill([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'two_factor_enrollment_suspended_at' => null,
            ])->saveQuietly();
        });

        $this->events->dispatch(new TwoFactorDisabled(
            user: $user->refresh(),
            ip: $request->ip(),
            userAgent: $request->userAgent(),
        ));
    }

    /**
     * @return list<string> plaintext recovery codes (shown once, never
     *                      retrievable from the database afterwards).
     */
    public function regenerateRecoveryCodes(User $user, Request $request): array
    {
        $plainCodes = $this->twoFactor->generateRecoveryCodes();
        $hashedCodes = array_map(
            fn (string $plain): string => $this->twoFactor->hashRecoveryCode($plain),
            $plainCodes,
        );

        $this->db->transaction(function () use ($user, $hashedCodes): void {
            $user->forceFill([
                'two_factor_recovery_codes' => array_values($hashedCodes),
            ])->saveQuietly();
        });

        $this->events->dispatch(new TwoFactorRecoveryCodesRegenerated(
            user: $user->refresh(),
            codeCount: count($plainCodes),
            ip: $request->ip(),
            userAgent: $request->userAgent(),
        ));

        return $plainCodes;
    }

    private function cacheKey(User $user, string $token): string
    {
        return self::CACHE_PREFIX.$user->getKey().':'.$token;
    }
}
