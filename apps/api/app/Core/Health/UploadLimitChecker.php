<?php

declare(strict_types=1);

namespace App\Core\Health;

/**
 * Verifies that the PHP runtime can actually accept the uploads the
 * application advertises.
 *
 * The application caps avatar uploads at `config('uploads.avatar_max_bytes')`,
 * but whether an upload of that size SUCCEEDS depends on runtime config that
 * lives outside the codebase:
 *
 *   - PHP `upload_max_filesize` — max size of a single uploaded file.
 *   - PHP `post_max_size`       — max size of the whole request body
 *                                 (0 == unlimited).
 *
 * When either is set below the application cap, PHP silently discards the
 * upload before the framework sees it, surfacing as a confusing generic
 * error to the user. This checker lets `/health` and the
 * `uploads:check-limits` command flag the misconfiguration instead.
 *
 * IMPORTANT — this can only see the PHP layer. A reverse proxy in front of
 * PHP-FPM (e.g. nginx `client_max_body_size`, default 1 MB) enforces its own
 * cap that PHP cannot introspect. The only way to verify the FULL chain is an
 * end-to-end upload against the deployed environment (see docs/tech-debt.md).
 */
final class UploadLimitChecker
{
    /**
     * The application's advertised maximum upload size in bytes.
     */
    public function requiredBytes(): int
    {
        return (int) config('uploads.avatar_max_bytes');
    }

    /**
     * The largest single upload the PHP runtime will accept, in bytes —
     * the smaller of `upload_max_filesize` and `post_max_size` (the latter
     * only constrains when it is a positive, i.e. non-"unlimited", value).
     */
    public function effectiveCeilingBytes(): int
    {
        $uploadMax = self::parseBytes((string) ini_get('upload_max_filesize'));
        $postMax = self::parseBytes((string) ini_get('post_max_size'));

        // `upload_max_filesize` always bounds a single file. A value <= 0
        // means uploads are effectively disabled (ceiling 0).
        $ceiling = $uploadMax;

        // `post_max_size` of 0 means "unlimited" and therefore does not
        // constrain; any positive value further caps the request body.
        if ($postMax > 0) {
            $ceiling = min($ceiling, $postMax);
        }

        return $ceiling;
    }

    /**
     * Whether the runtime can accept an upload of the advertised size.
     */
    public function isSatisfied(): bool
    {
        return $this->effectiveCeilingBytes() >= $this->requiredBytes();
    }

    /**
     * Parse a PHP ini shorthand byte value ("2M", "8M", "512K", "1G", "0",
     * a bare integer) into bytes. Returns 0 for an empty value. Pure +
     * static so the parsing is unit-testable without touching ini state.
     */
    public static function parseBytes(string $raw): int
    {
        $raw = trim($raw);
        if ($raw === '') {
            return 0;
        }

        $value = (int) $raw;
        $unit = strtolower($raw[strlen($raw) - 1]);

        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }
}
