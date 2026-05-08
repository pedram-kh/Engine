<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Hashing\HashManager;
use PragmaRX\Google2FA\Google2FA;
use SensitiveParameter;

/**
 * The single seam between the Identity module and the underlying TOTP /
 * QR code libraries.
 *
 * Chunk 5 priority #1: every other class — controllers, middleware,
 * listeners, even the rest of the Identity module — must reach Google2FA
 * and BaconQrCode through this service. A repo-wide grep that proves
 * `PragmaRX\Google2FA\` and `BaconQrCode\` only appear inside
 * {@see TwoFactorService} (and its dedicated test file) is part of
 * the chunk 5 review checklist. If a future module needs raw access,
 * add a method to this service rather than reaching past it.
 *
 * The service is intentionally stateless and side-effect-free. State
 * lives on the {@see User} row and on the
 * cache (provisional enrollment); this object only provides the
 * cryptographic primitives.
 */
final class TwoFactorService
{
    /**
     * 32 base32 characters → 160 bits of entropy, well above the
     * RFC 6238 16-byte (128-bit) recommendation and what every
     * authenticator app on the market handles cleanly.
     */
    private const SECRET_LENGTH = 32;

    /**
     * Verifier window. 1 = ±30s either side of "now" (the standard
     * tolerance for clock drift on a phone). Intentionally small; the
     * library default is also 1.
     */
    private const VERIFY_WINDOW = 1;

    /**
     * Ten codes is the Google / Authy / 1Password convention. Each
     * code is 4-4 hex (16 hex chars + dash = 17 chars total),
     * giving 64 bits of entropy per code — comfortably ahead of the
     * 50 bits a 6-digit TOTP gives across a full 30-second window.
     */
    private const RECOVERY_CODE_COUNT = 10;

    public function __construct(
        private readonly Google2FA $google2fa,
        private readonly HashManager $hash,
    ) {}

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey(self::SECRET_LENGTH);
    }

    /**
     * Build the otpauth:// URL that the SPA encodes into a QR code or
     * presents as a manual entry string. The issuer and account name
     * are user-visible inside the authenticator app.
     */
    public function otpauthUrl(string $issuer, string $accountName, #[SensitiveParameter] string $secret): string
    {
        return $this->google2fa->getQRCodeUrl($issuer, $accountName, $secret);
    }

    /**
     * Render the otpauth URL as an inline SVG (no GD/Imagick dependency,
     * trivially served with `Content-Type: image/svg+xml` or embedded
     * in a data URL by the SPA).
     */
    public function qrCodeSvg(string $otpauthUrl, int $size = 240): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle($size),
            new SvgImageBackEnd,
        );

        return (new Writer($renderer))->writeString($otpauthUrl);
    }

    /**
     * Constant-time, library-internal comparison of a 6-digit code
     * against the provided secret. Returns false for any malformed
     * input rather than throwing, so callers don't have to wrap in
     * try/catch on the user-supplied code path.
     */
    public function verifyTotp(#[SensitiveParameter] string $secret, #[SensitiveParameter] string $code): bool
    {
        $code = trim($code);

        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        try {
            return $this->google2fa->verifyKey($secret, $code, self::VERIFY_WINDOW) !== false;
        } catch (\Throwable) {
            // Defensive: treat any decoding error from the library as
            // "wrong code" rather than 500ing the request. Tests cover
            // a handful of malformed-secret edge cases.
            return false;
        }
    }

    /**
     * @return list<string> plaintext recovery codes formatted as
     *                      `xxxx-xxxx-xxxx-xxxx` (16 lowercase hex chars
     *                      + 3 dashes). The caller is responsible for
     *                      hashing them before persistence and showing
     *                      the plaintext to the user exactly once.
     */
    public function generateRecoveryCodes(): array
    {
        $codes = [];

        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $codes[] = $this->generateRecoveryCode();
        }

        return $codes;
    }

    /**
     * Bcrypt the plaintext code. We use bcrypt (chunk 5 priority #3)
     * because recovery codes are short, fixed-format, and high entropy
     * — Argon2's memory cost gives no security benefit on a 64-bit
     * input and would dominate the verify path. Bcrypt with the
     * Laravel default cost (10) verifies in ~10ms.
     *
     * The driver is selected explicitly so the application-wide
     * Argon2id default for passwords (chunk 3) doesn't bleed in here.
     */
    public function hashRecoveryCode(#[SensitiveParameter] string $plain): string
    {
        return $this->hash->driver('bcrypt')->make($plain);
    }

    public function checkRecoveryCode(#[SensitiveParameter] string $plain, #[SensitiveParameter] string $hash): bool
    {
        // Hasher::check() throws when handed a hash that isn't in the
        // expected algorithm. We treat that defensively as "wrong code"
        // so a corrupted/migrated row never 500s the login flow — the
        // user just sees an invalid-code response and admin can clear
        // the bad data out of band.
        try {
            return $this->hash->driver('bcrypt')->check($plain, $hash);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Heuristic used by the login flow to decide whether to dispatch
     * a candidate `mfa_code` value to the TOTP verifier or the
     * recovery-code verifier.
     *
     * A 6-digit numeric is always TOTP; anything else is treated as a
     * recovery code candidate (the recovery-code path is constant-time
     * over the user's stored hashes and rejects malformed input).
     */
    public function looksLikeTotpCode(string $candidate): bool
    {
        return (bool) preg_match('/^\d{6}$/', trim($candidate));
    }

    private function generateRecoveryCode(): string
    {
        $hex = bin2hex(random_bytes(8)); // 16 lower-hex characters

        return implode('-', str_split($hex, 4));
    }
}
