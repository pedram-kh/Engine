<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Services;

use App\Modules\Strategy\Models\WaveConceptImage;
use Illuminate\Support\Facades\Log;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * The 512 px thumbnail of a concept image (PL-2.3 plan, "Thumbnail thresholds"). It does NOT decode an image above either
 * threshold: the image keeps no thumbnail (the placeholder tile) and still downloads. A GD decode holds the whole file
 * as a string plus a bitmap of about 4 bytes a pixel, times 2 to 3 for decoder overhead (AH-004 §6); the queue worker
 * runs at 768 MB (`--memory=768`, and the job raises `memory_limit` to 768M). 40 MP is about 160 MB decoded, 320 to 480 MB
 * with the overhead; 32 MB of file bytes sit beside it.
 */
final class ConceptThumbnailer
{
    public const int MAX_PIXELS = 40_000_000;

    public const int MAX_BYTES = 32 * 1024 * 1024;

    public const int MAX_SIDE = 30_000;

    public const int EDGE = 512;

    public function __construct(private readonly ?ImageManager $manager = null) {}

    /** True when a thumbnail was written. Never throws: any trouble leaves the placeholder. */
    public function generate(WaveConceptImage $image): bool
    {
        if ($image->status !== WaveConceptImage::READY || $image->thumb_key !== null) {
            return false;
        }
        if (($image->size_bytes ?? 0) > self::MAX_BYTES) {
            Log::info('Concept image not thumbnailed: over the byte threshold.', ['image' => $image->ulid]);

            return false;
        }

        $head = ConceptImageStorage::head($image->storage_key);
        $sniff = $head === null ? null : ConceptImageStorage::sniff($head);
        if ($sniff === null || $sniff['width'] < 1 || $sniff['height'] < 1
            || $sniff['width'] > self::MAX_SIDE || $sniff['height'] > self::MAX_SIDE
            || $sniff['width'] * $sniff['height'] > self::MAX_PIXELS) {
            Log::info('Concept image not thumbnailed: over the pixel threshold or unreadable header.', ['image' => $image->ulid]);

            return false;
        }

        try {
            $binary = ConceptImageStorage::disk()->get($image->storage_key);
            if (! is_string($binary) || $binary === '') {
                return false;
            }
            $manager = $this->manager ?? new ImageManager(new GdDriver);
            $decoded = $manager->decodeBinary($binary);
            unset($binary);
            $decoded->scaleDown(width: self::EDGE, height: self::EDGE);
            $jpeg = (string) $decoded->encode(new JpegEncoder(quality: 80, strip: true));

            $key = ConceptImageStorage::thumbKey(ConceptImageStorage::prefixOfKey($image->storage_key), $image->ulid);
            if (ConceptImageStorage::disk()->put($key, $jpeg) !== true) {
                return false;
            }
            $image->thumb_key = $key;
            $image->save();

            return true;
        } catch (Throwable $e) {
            Log::warning('Concept image thumbnail failed.', ['image' => $image->ulid, 'error' => $e::class]);

            return false;
        }
    }
}
