<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Support;

use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;

/**
 * AH-013 — resolves a stored avatar/logo reference to a viewable URL for the
 * relationship-messaging CONTACT surfaces (inbox rows, contact pickers, and the
 * thread header), on BOTH sides.
 *
 * Two reference shapes are supported, because the two assets are stored
 * differently today:
 *   - creator `avatar_path` — always a private `media`-disk S3 KEY, so it is
 *     minted as a short-lived signed GET URL (the CreatorResource / discovery /
 *     talent-pool precedent: per-row signing, acceptable on a bounded list).
 *   - agency `logo_path` — spec'd as an S3 key but, with no upload pipeline wired
 *     yet, seeded as an absolute http(s) URL (CDN). So an already-absolute URL is
 *     passed through verbatim; a bare key is signed like an avatar.
 *
 * Returns null when there is no reference, or when the `media` disk is not S3
 * (e.g. Storage::fake's local driver in tests, which can't sign) — exactly the
 * per-resource signedViewUrl behaviour, kept in ONE place so the messaging
 * touchpoints can't drift.
 */
final class ContactMediaUrl
{
    private const SIGNED_URL_TTL_MINUTES = 60;

    public static function resolve(?string $reference): ?string
    {
        if ($reference === null || $reference === '') {
            return null;
        }

        // Already a usable absolute URL (agency logo CDN / seed data) — passthrough.
        if (preg_match('#^https?://#i', $reference) === 1) {
            return $reference;
        }

        $disk = Storage::disk('media');
        if (! $disk instanceof AwsS3V3Adapter) {
            return null;
        }

        return $disk->temporaryUrl($reference, now()->addMinutes(self::SIGNED_URL_TTL_MINUTES));
    }
}
