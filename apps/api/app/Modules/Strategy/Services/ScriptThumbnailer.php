<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Services;

use App\Modules\Strategy\Models\WaveScriptImage;
use Illuminate\Support\Facades\Log;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * The 512 px thumbnail of a script image (PL-3.2b), a sibling of {@see ConceptThumbnailer} with the same thresholds and the same
 * rule: an image above either threshold is NOT decoded and keeps no thumbnail (the placeholder tile); it still downloads. A GIF is
 * decoded to its FIRST frame only and the thumbnail is a JPEG; the original is served as stored, so an animated GIF still plays
 * in the viewer. Never throws.
 */
final class ScriptThumbnailer
{
    public function __construct(private readonly ?ImageManager $manager = null) {}

    /** True when a thumbnail was written. */
    public function generate(WaveScriptImage $image): bool
    {
        if ($image->status !== WaveScriptImage::READY || $image->thumb_key !== null) {
            return false;
        }
        if (($image->size_bytes ?? 0) > ConceptThumbnailer::MAX_BYTES) {
            Log::info('Script image not thumbnailed: over the byte threshold.', ['image' => $image->ulid]);

            return false;
        }

        $head = ConceptImageStorage::head($image->storage_key);
        $sniff = $head === null ? null : ScriptImageStorage::sniff($head);
        if ($sniff === null || $sniff['width'] < 1 || $sniff['height'] < 1
            || $sniff['width'] > ConceptThumbnailer::MAX_SIDE || $sniff['height'] > ConceptThumbnailer::MAX_SIDE
            || $sniff['width'] * $sniff['height'] > ConceptThumbnailer::MAX_PIXELS) {
            Log::info('Script image not thumbnailed: over the pixel threshold or unreadable header.', ['image' => $image->ulid]);

            return false;
        }

        try {
            $binary = ScriptImageStorage::disk()->get($image->storage_key);
            if (! is_string($binary) || $binary === '') {
                return false;
            }
            $manager = $this->manager ?? new ImageManager(new GdDriver);
            $decoded = $manager->decodeBinary($binary);
            unset($binary);
            // An animated GIF decodes to a frame collection; the thumbnail is the first frame.
            $decoded->scaleDown(width: ConceptThumbnailer::EDGE, height: ConceptThumbnailer::EDGE);
            $jpeg = (string) $decoded->encode(new JpegEncoder(quality: 80, strip: true));

            $key = ScriptImageStorage::thumbKey(ScriptImageStorage::prefixOfKey($image->storage_key), $image->ulid);
            if (ScriptImageStorage::disk()->put($key, $jpeg) !== true) {
                return false;
            }
            $image->thumb_key = $key;
            $image->save();

            return true;
        } catch (Throwable $e) {
            Log::warning('Script image thumbnail failed.', ['image' => $image->ulid, 'error' => $e::class]);

            return false;
        }
    }
}
