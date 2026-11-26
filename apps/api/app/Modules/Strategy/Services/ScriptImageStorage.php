<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Services;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Models\Brand;
use App\Modules\Strategy\Models\WaveScript;
use App\Modules\Strategy\Models\WaveScriptImage;
use App\Modules\Waves\Models\Wave;
use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * Where script reference images live (PL-3.2b). The `media` disk; keys, made of ulids only (never a file name):
 *
 *   agencies/{agency}/brands/{brand}/waves/{wave}/scripts/{script}/images/{image}.{ext}
 *   agencies/{agency}/brands/{brand}/waves/{wave}/scripts/{script}/thumbs/{image}.jpg
 *
 * A SIBLING of {@see ConceptImageStorage} (which stays three-type and concept-shaped, untouched): the raw disk operations are
 * that class's static helpers, called as they are; only the four accepted types, the key scheme and the header check differ.
 * A missing object counts as deleted.
 */
final class ScriptImageStorage
{
    /** @var array<string, string> mime => extension: the four accepted types. */
    public const array MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    public static function disk(): Filesystem
    {
        return ConceptImageStorage::disk();
    }

    public static function scriptPrefix(Agency $agency, Brand $brand, Wave $wave, WaveScript $script): string
    {
        return sprintf('agencies/%s/brands/%s/waves/%s/scripts/%s/', $agency->ulid, $brand->ulid, $wave->ulid, $script->ulid);
    }

    public static function imageKey(string $scriptPrefix, string $imageUlid, string $extension): string
    {
        return $scriptPrefix.'images/'.$imageUlid.'.'.$extension;
    }

    public static function thumbKey(string $scriptPrefix, string $imageUlid): string
    {
        return $scriptPrefix.'thumbs/'.$imageUlid.'.jpg';
    }

    /** The script prefix a stored key sits under: everything before `images/` or `thumbs/`. */
    public static function prefixOfKey(string $key): string
    {
        return ConceptImageStorage::prefixOfKey($key);
    }

    /** Every object an image owns (the original and, when there is one, the thumbnail). @return list<string> */
    public static function keysOf(WaveScriptImage $image): array
    {
        return array_values(array_filter([$image->storage_key, $image->thumb_key], static fn (?string $k): bool => $k !== null && $k !== ''));
    }

    /**
     * What the header bytes say the file is: mime and pixel size, or null when it is not a JPEG, PNG, WebP or GIF image. A GIF
     * must also begin `GIF87a` or `GIF89a`.
     *
     * @return array{mime: string, width: int, height: int}|null
     */
    public static function sniff(string $head): ?array
    {
        $info = @getimagesizefromstring($head);
        if ($info === false) {
            return null;
        }
        $mime = match ($info[2]) {
            IMAGETYPE_JPEG => 'image/jpeg',
            IMAGETYPE_PNG => 'image/png',
            IMAGETYPE_WEBP => 'image/webp',
            IMAGETYPE_GIF => in_array(substr($head, 0, 6), ['GIF87a', 'GIF89a'], true) ? 'image/gif' : null,
            default => null,
        };

        return $mime === null ? null : ['mime' => $mime, 'width' => (int) $info[0], 'height' => (int) $info[1]];
    }
}
