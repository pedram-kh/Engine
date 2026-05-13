<?php

declare(strict_types=1);

namespace App\Modules\Creators\Services;

use App\Modules\Creators\Models\Creator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use RuntimeException;

/**
 * Direct multipart upload for creator avatars.
 *
 * Per docs/05-SECURITY-COMPLIANCE.md §10.4 + docs/04-API-DESIGN.md §19:
 *   - Max 5MB (well under the 10MB direct-upload threshold).
 *   - Validation via magic-bytes (MIME inferred from content, not header).
 *   - Re-encoded via Intervention Image to strip EXIF / GPS metadata.
 *   - Filename sanitised — server picks a fresh ULID-suffixed filename
 *     so client-supplied names never reach the storage path.
 *   - Per-creator-scoped path: creators/{ulid}/avatar/{file_ulid}.{ext}.
 *
 * The avatar disk is `media`. The `media-public` disk would be used IF
 * avatars were served from a CDN-fronted unauthenticated URL; today the
 * SPA fetches via authenticated proxy, so `media` (private) is correct.
 */
final class AvatarUploadService
{
    public const int MAX_BYTES = 5 * 1024 * 1024;

    /**
     * MIME types accepted on the avatar surface. Mapped to canonical
     * file extensions for the saved object.
     *
     * @var array<string, string>
     */
    public const array ACCEPTED_MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(
        private readonly ImageManager $imageManager = new ImageManager(new GdDriver),
    ) {}

    /**
     * Stores the avatar to the `media` disk and returns the persisted
     * path. Caller is responsible for assigning the path to
     * Creator::avatar_path inside its own transaction.
     *
     * @throws RuntimeException When validation fails. The message is
     *                          intentionally generic — detailed error codes
     *                          are returned via the controller's form-request
     *                          path (this service is a low-level helper).
     */
    public function upload(Creator $creator, UploadedFile $file): string
    {
        $this->assertWithinSize($file);
        $extension = $this->resolveExtension($file);

        $reencoded = $this->reencode($file, $extension);

        $path = sprintf(
            'creators/%s/avatar/%s.%s',
            $creator->ulid,
            (string) Str::ulid(),
            $extension,
        );

        Storage::disk('media')->put($path, $reencoded);

        return $path;
    }

    public function delete(string $path): void
    {
        if ($path === '') {
            return;
        }

        Storage::disk('media')->delete($path);
    }

    /**
     * Public accessor for the validated extension — used by the form
     * request to surface the rejection reason without re-running the
     * mime check.
     *
     * @throws RuntimeException When the upload does not pass the magic-byte
     *                          check.
     */
    public function resolveExtension(UploadedFile $file): string
    {
        $mime = $this->detectMime($file);

        if (! array_key_exists($mime, self::ACCEPTED_MIME_TYPES)) {
            throw new RuntimeException("Unsupported avatar MIME type: {$mime}.");
        }

        return self::ACCEPTED_MIME_TYPES[$mime];
    }

    private function assertWithinSize(UploadedFile $file): void
    {
        if ($file->getSize() > self::MAX_BYTES) {
            throw new RuntimeException('Avatar exceeds 5MB size limit.');
        }
    }

    /**
     * Magic-byte MIME detection (NOT the client-provided header). The
     * UploadedFile::getMimeType() implementation reads the file content
     * via SymfonyMimeTypeGuesser which uses fileinfo / file extension as
     * a fallback chain.
     */
    private function detectMime(UploadedFile $file): string
    {
        $real = $file->getMimeType();
        if (! is_string($real) || $real === '') {
            throw new RuntimeException('Could not determine MIME type.');
        }

        return $real;
    }

    /**
     * Re-encode the image to strip EXIF / GPS metadata + any malicious
     * payload masquerading as image content. Intervention's encode()
     * produces a fresh stream from the decoded pixel buffer; the source
     * file is not preserved.
     */
    private function reencode(UploadedFile $file, string $extension): string
    {
        // Intervention v4 dropped the v3 read() helper in favour of the
        // explicit decodePath() (file path) / decodeBinary() (string)
        // entrypoints. We use decodePath since UploadedFile already
        // resolves to a real path on disk.
        $image = $this->imageManager->decodePath($file->getRealPath());

        // Constrain to a reasonable max dimension. Avatars don't need
        // 8k source — clamp to 1024px on the longest side, preserving
        // aspect ratio. Sprint 4+ may add tier-specific sizes.
        $image->scaleDown(width: 1024, height: 1024);

        // Intervention v4 encoders. Passing strip: true on JpegEncoder
        // would normally remove EXIF; the encode-from-decoded-pixels
        // flow already drops EXIF as a side effect of re-rendering
        // from the raster, so the strip flag is defense-in-depth.
        $encoded = match ($extension) {
            'jpg' => $image->encode(new JpegEncoder(quality: 85, strip: true)),
            'png' => $image->encode(new PngEncoder),
            'webp' => $image->encode(new WebpEncoder(quality: 85)),
            default => throw new RuntimeException("Unsupported re-encoding extension: {$extension}."),
        };

        return (string) $encoded;
    }
}
