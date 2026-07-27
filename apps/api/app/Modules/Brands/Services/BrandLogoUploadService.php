<?php

declare(strict_types=1);

namespace App\Modules\Brands\Services;

use App\Core\Storage\StorageWriteFailedException;
use App\Modules\Brands\Models\Brand;
use App\Modules\Creators\Services\AvatarUploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\Exceptions\ImageException;
use Intervention\Image\ImageManager;
use RuntimeException;

/**
 * Direct multipart upload for brand logos (AH-053, D7) — the sibling of
 * {@see AvatarUploadService}.
 *
 * Why the avatar pattern and not the AH-034 presigned-attachment pattern: a
 * logo is a small image that the server must RE-ENCODE (EXIF strip, scale
 * down, canonical format). Presigned PUT hands the object straight to S3 with
 * no server-side pass, which is right for multi-megabyte offer attachments and
 * wrong for an image the platform will render on a public-facing job card.
 *
 *   - Max {@see maxBytes()} (config/uploads.php, covered by the /health check).
 *   - MIME inferred from CONTENT (magic bytes), never the client's header.
 *   - Re-encoded through Intervention: EXIF/GPS stripped, scaled to 1024px.
 *   - Server-chosen path, so no client-supplied name reaches storage.
 *   - Path carries BOTH the agency and brand ULIDs:
 *     `agencies/{agency_ulid}/brands/{brand_ulid}/logo/{file_ulid}.{ext}`.
 *     The agency segment is what makes cross-tenant reads impossible to
 *     stumble into: an object's tenant is legible from its key, and a bucket
 *     policy or prefix audit can be written against it later.
 *
 * The disk is `media` (private). Logos are emitted as short-lived signed URLs
 * from inside authorized resource serialisation — the ContactMediaUrl posture
 * — never as public objects.
 */
final class BrandLogoUploadService
{
    /**
     * The three image types accepted, mapped to canonical extensions.
     *
     * @var array<string, string>
     */
    public const array ACCEPTED_MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /** How long a minted logo URL stays valid. */
    public const int SIGNED_URL_TTL_MINUTES = 60;

    public function __construct(
        private readonly ImageManager $imageManager = new ImageManager(new GdDriver),
    ) {}

    /**
     * Store the logo and return its path. The caller assigns it to
     * `Brand::logo_path` inside its own transaction.
     *
     * Replace-in-place is by REFERENCE, not by object: a new upload writes a
     * new key and repoints the column. The previous object is left behind,
     * mirroring the avatar precedent — object deletion happens only on the
     * explicit remove action, which keeps exactly one code path capable of
     * destroying a stored file.
     *
     * @throws RuntimeException when the file fails the size or MIME check
     * @throws StorageWriteFailedException when the disk reports the write failed
     */
    public function upload(Brand $brand, UploadedFile $file): string
    {
        $this->assertWithinSize($file);
        $extension = $this->resolveExtension($file);

        $reencoded = $this->reencode($file, $extension);

        $path = sprintf(
            'agencies/%s/brands/%s/logo/%s.%s',
            $brand->agency->ulid,
            $brand->ulid,
            (string) Str::ulid(),
            $extension,
        );

        if (Storage::disk('media')->put($path, $reencoded) === false) {
            throw StorageWriteFailedException::forPath('media', $path);
        }

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
     * Mint a short-lived signed GET for a stored logo.
     *
     * Call this ONLY from inside an authorized emission (the brand resource,
     * which is already behind the brand policy). A signed URL is a bearer
     * credential: minting one is equivalent to granting read access, so it
     * must never be produced on an unauthenticated path.
     */
    public static function signedViewUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        return Storage::disk('media')->temporaryUrl(
            $path,
            now()->addMinutes(self::SIGNED_URL_TTL_MINUTES),
        );
    }

    /**
     * The validated extension for this upload — used by the controller to
     * reject unsupported content without re-running the check.
     *
     * @throws RuntimeException when the magic-byte check fails
     */
    public function resolveExtension(UploadedFile $file): string
    {
        $mime = $this->detectMime($file);

        if (! array_key_exists($mime, self::ACCEPTED_MIME_TYPES)) {
            throw new RuntimeException("Unsupported logo MIME type: {$mime}.");
        }

        return self::ACCEPTED_MIME_TYPES[$mime];
    }

    /** The configured cap in bytes — single source of truth. */
    public function maxBytes(): int
    {
        return (int) config('uploads.brand_logo_max_bytes');
    }

    private function assertWithinSize(UploadedFile $file): void
    {
        if ($file->getSize() > $this->maxBytes()) {
            throw new RuntimeException('Logo exceeds the configured size limit.');
        }
    }

    /**
     * MIME from content, not from the client. A `.png`-named PHP script
     * detects as `text/x-php` here and is refused before any decode.
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
     * Re-encode from the decoded pixel buffer. This is what strips EXIF/GPS
     * and neutralises any payload smuggled in a file that merely LOOKS like
     * an image — the output is a fresh stream, never the uploaded bytes.
     */
    private function reencode(UploadedFile $file, string $extension): string
    {
        try {
            $image = $this->imageManager->decodePath($file->getRealPath());
        } catch (ImageException $e) {
            // Intervention's exceptions extend \Exception, NOT \RuntimeException,
            // so an undecodable file (truncated PNG, a payload that satisfied the
            // MIME sniff but is not a real raster) would escape the controller's
            // catch and surface as a 500. Translate it into the same rejection
            // shape as every other content failure on this surface.
            throw new RuntimeException('The image could not be decoded.', previous: $e);
        }

        // A logo never needs to exceed this on a job card or brand header.
        $image->scaleDown(width: 1024, height: 1024);

        $encoded = match ($extension) {
            'jpg' => $image->encode(new JpegEncoder(quality: 85, strip: true)),
            'png' => $image->encode(new PngEncoder),
            'webp' => $image->encode(new WebpEncoder(quality: 85)),
            default => throw new RuntimeException("Unsupported re-encoding extension: {$extension}."),
        };

        return (string) $encoded;
    }
}
