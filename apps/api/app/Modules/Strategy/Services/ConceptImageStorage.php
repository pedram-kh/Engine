<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Services;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Models\Brand;
use App\Modules\Strategy\Models\WaveConcept;
use App\Modules\Strategy\Models\WaveConceptImage;
use App\Modules\Waves\Models\Wave;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Where concept reference images live and the few raw disk operations on them (PL-2.3 plan sections 1.5, 1.7, 1.8). The
 * `media` disk; keys:
 *
 *   agencies/{agency}/brands/{brand}/waves/{wave}/concepts/{concept}/images/{image}.{ext}
 *   agencies/{agency}/brands/{brand}/waves/{wave}/concepts/{concept}/thumbs/{image}.jpg
 *
 * The key is made of ulids only (never a file name). A missing object counts as deleted.
 */
final class ConceptImageStorage
{
    /** @var array<string, string> mime => extension: the three accepted types; `AttachmentMimeTypes` is not edited. */
    public const array MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public static function disk(): Filesystem
    {
        return Storage::disk('media');
    }

    public static function conceptPrefix(Agency $agency, Brand $brand, Wave $wave, WaveConcept $concept): string
    {
        return sprintf('agencies/%s/brands/%s/waves/%s/concepts/%s/', $agency->ulid, $brand->ulid, $wave->ulid, $concept->ulid);
    }

    public static function imageKey(string $conceptPrefix, string $imageUlid, string $extension): string
    {
        return $conceptPrefix.'images/'.$imageUlid.'.'.$extension;
    }

    public static function thumbKey(string $conceptPrefix, string $imageUlid): string
    {
        return $conceptPrefix.'thumbs/'.$imageUlid.'.jpg';
    }

    /** The concept prefix a stored key sits under: everything before `images/` or `thumbs/`. */
    public static function prefixOfKey(string $key): string
    {
        $pos = strrpos($key, '/images/');
        if ($pos === false) {
            $pos = strrpos($key, '/thumbs/');
        }

        return $pos === false ? '' : substr($key, 0, $pos + 1);
    }

    /** Every object an image owns (the original and, when there is one, the thumbnail). @return list<string> */
    public static function keysOf(WaveConceptImage $image): array
    {
        return array_values(array_filter([$image->storage_key, $image->thumb_key], static fn (?string $k): bool => $k !== null && $k !== ''));
    }

    /** Delete objects; true only when every delete is confirmed (a missing object is a success). @param  list<string>  $keys */
    public static function deleteAll(array $keys): bool
    {
        $disk = self::disk();
        foreach ($keys as $key) {
            try {
                if ($disk->delete($key) !== true) {
                    return false;
                }
            } catch (Throwable) {
                return false;
            }
        }

        return true;
    }

    /** Server-side copy to a NEW key (never a shared object). */
    public static function copy(string $from, string $to): bool
    {
        try {
            return self::disk()->copy($from, $to) === true;
        } catch (Throwable) {
            return false;
        }
    }

    /** The first bytes of an object (for the header check and the pixel guard), without reading the whole file. */
    public static function head(string $key, int $bytes = 65536): ?string
    {
        try {
            $stream = self::disk()->readStream($key);
            if (! is_resource($stream)) {
                return null;
            }
            $data = stream_get_contents($stream, $bytes);
            fclose($stream);

            return is_string($data) && $data !== '' ? $data : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * What the header bytes say the file is: mime and pixel size, or null when it is not a JPEG, PNG or WebP image.
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
            default => null,
        };

        return $mime === null ? null : ['mime' => $mime, 'width' => (int) $info[0], 'height' => (int) $info[1]];
    }
}
