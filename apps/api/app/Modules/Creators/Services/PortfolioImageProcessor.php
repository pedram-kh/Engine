<?php

declare(strict_types=1);

namespace App\Modules\Creators\Services;

use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use RuntimeException;

/**
 * Full-resolution portfolio-image sanitiser (ad-hoc AH-004 Q5).
 *
 * Unlike {@see AvatarUploadService} — which downscales to 1024px because an
 * avatar never needs more — portfolio images are the creator's WORK and must
 * keep their full resolution. This processor therefore:
 *
 *   1. Guards against decompression bombs BEFORE decoding, using the native
 *      `getimagesizefromstring()` (reads the header only — it does NOT
 *      allocate the full bitmap). Decode memory scales with PIXELS, not file
 *      bytes, so the byte ceiling alone is not enough. The cap is a MATCHED
 *      PAIR with the worker memory (see docs/reviews/ah-004-portfolio-overhaul-plan.md
 *      §6): MAX_MEGAPIXELS = 50 keeps a near-cap decode inside the 512 MB–768 MB
 *      envelope that the `composer test` pin and the prod `queue:work --memory`
 *      both clear.
 *   2. Re-encodes at FULL resolution (no scaleDown) — the encode-from-decoded-
 *      pixels flow drops EXIF/GPS as a side effect, and `strip: true` is
 *      defence-in-depth. The sanitised bytes overwrite the raw upload so the
 *      GPS-bearing original is destroyed.
 *   3. Produces a SEPARATE small thumbnail (≤512px longest side) for the grid,
 *      so the gallery never has to load the full-res asset just to render a
 *      tile.
 *
 * Over the megapixel cap (or any decode failure) → the caller marks the item
 * `failed`; nothing is downscaled silently.
 */
final class PortfolioImageProcessor
{
    /**
     * Decompression-bomb / worker-memory ceiling. 50 MP sits above 48 MP
     * phones and 45 MP pro DSLRs (real creator content) while keeping a
     * near-cap decode within the pinned memory envelope. Raising this REQUIRES
     * raising the test pin AND the prod worker memory together (§6).
     */
    public const int MAX_MEGAPIXELS = 50;

    /**
     * Longest-side ceiling for the generated grid thumbnail.
     */
    public const int THUMBNAIL_MAX_EDGE = 512;

    /**
     * @var array<string, string>
     */
    private const array EXTENSION_BY_MIME = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(
        private readonly ImageManager $imageManager = new ImageManager(new GdDriver),
    ) {}

    /**
     * Decode dimensions WITHOUT allocating the full bitmap, and reject any
     * image whose pixel count exceeds the cap (decompression-bomb guard).
     *
     * @throws RuntimeException when the header is unreadable or over the cap.
     */
    public function assertWithinMegapixelCap(string $binary): void
    {
        $info = @getimagesizefromstring($binary);
        if ($info === false) {
            throw new RuntimeException('Unreadable or corrupt image — cannot determine dimensions.');
        }

        $megapixels = ((int) $info[0] * (int) $info[1]) / 1_000_000;
        if ($megapixels > self::MAX_MEGAPIXELS) {
            throw new RuntimeException(
                sprintf('Image exceeds the %d MP ceiling (decoded ~%.1f MP).', self::MAX_MEGAPIXELS, $megapixels),
            );
        }
    }

    /**
     * Sanitise a raw image: returns the full-resolution EXIF-stripped bytes
     * and a separate thumbnail, in the same on-disk extension.
     *
     * @return array{full: string, thumbnail: string}
     *
     * @throws RuntimeException on an over-cap or undecodable image.
     */
    public function process(string $binary, string $extension): array
    {
        $this->assertWithinMegapixelCap($binary);

        // Decode once; encode the full-res sanitised image first (encode()
        // does not mutate the decoded buffer), then scale a copy down for the
        // thumbnail.
        $image = $this->imageManager->decodeBinary($binary);

        $full = $this->encode($image, $extension);

        $image->scaleDown(width: self::THUMBNAIL_MAX_EDGE, height: self::THUMBNAIL_MAX_EDGE);
        $thumbnail = $this->encode($image, $extension);

        return ['full' => $full, 'thumbnail' => $thumbnail];
    }

    /**
     * Map a MIME type to the canonical extension this processor encodes to,
     * or null when the MIME is not an accepted portfolio image type.
     */
    public function extensionForMime(?string $mime): ?string
    {
        if ($mime === null) {
            return null;
        }

        return self::EXTENSION_BY_MIME[$mime] ?? null;
    }

    private function encode(ImageInterface $image, string $extension): string
    {
        $encoded = match ($extension) {
            'jpg' => $image->encode(new JpegEncoder(quality: 90, strip: true)),
            'png' => $image->encode(new PngEncoder),
            'webp' => $image->encode(new WebpEncoder(quality: 90)),
            default => throw new RuntimeException("Unsupported portfolio image extension: {$extension}."),
        };

        return (string) $encoded;
    }
}
