<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

/**
 * Carries the only-shown-once payload from
 * {@see TwoFactorEnrollmentService::start()} back to the controller:
 * the provisional token (which the user echoes back on /confirm), the
 * otpauth URL, an SVG QR code, and the plaintext secret for users who
 * prefer manual entry. NONE of this state is persisted to the
 * `users` table — it lives in cache for ~10 minutes and disappears.
 */
final readonly class TwoFactorEnrollmentResult
{
    /**
     * @param  string  $provisionalToken  cache key the SPA echoes back
     * @param  string  $otpauthUrl  otpauth:// URL for QR rendering
     * @param  string  $qrCodeSvg  inline SVG of the otpauth URL
     * @param  string  $manualEntryKey  base32 secret for manual entry
     * @param  int  $expiresInSeconds  cache TTL of the provisional state
     */
    public function __construct(
        public string $provisionalToken,
        public string $otpauthUrl,
        public string $qrCodeSvg,
        public string $manualEntryKey,
        public int $expiresInSeconds,
    ) {}
}
