<?php

declare(strict_types=1);

namespace App\Modules\Creators\Services;

use App\Modules\Creators\Models\Creator;
use Aws\S3\S3Client;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Portfolio uploads. Supports both direct multipart (images, ≤10MB) and
 * presigned-S3 (videos, up to 500MB) per docs/04-API-DESIGN.md §19.
 *
 * - Direct path: `uploadImage(...)` — synchronous, image re-encoded for
 *   EXIF stripping (delegated to AvatarUploadService's re-encoder).
 * - Presigned path: `initiatePresignedUpload(...)` returns the URL +
 *   upload-id; client PUTs the bytes directly to S3; client then calls
 *   `completePresignedUpload(...)` which links the file path to a
 *   creator_portfolio_items row.
 *
 * Per-creator-scoped paths:
 *   creators/{ulid}/portfolio/{file_ulid}.{ext}
 */
final class PortfolioUploadService
{
    public const int MAX_DIRECT_BYTES = 10 * 1024 * 1024; // 10MB

    public const int MAX_PRESIGNED_BYTES = 500 * 1024 * 1024; // 500MB

    public const int MAX_ITEMS_PER_CREATOR = 10;

    /**
     * @var array<string, string>
     */
    public const array ACCEPTED_IMAGE_MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * @var array<string, string>
     */
    public const array ACCEPTED_VIDEO_MIME_TYPES = [
        'video/mp4' => 'mp4',
        'video/quicktime' => 'mov',
        'video/webm' => 'webm',
    ];

    public function __construct(
        private readonly AvatarUploadService $reencoder,
    ) {}

    /**
     * Direct multipart upload for images. Delegates re-encoding to the
     * shared AvatarUploadService re-encoder so the EXIF-stripping path
     * is identical between avatars and image portfolio items.
     *
     * @throws RuntimeException
     */
    public function uploadImage(Creator $creator, UploadedFile $file): string
    {
        if ($file->getSize() > self::MAX_DIRECT_BYTES) {
            throw new RuntimeException('Direct upload exceeds 10MB. Use presigned upload for larger files.');
        }

        $mime = $file->getMimeType();
        if (! is_string($mime) || ! array_key_exists($mime, self::ACCEPTED_IMAGE_MIME_TYPES)) {
            throw new RuntimeException("Unsupported image MIME type: {$mime}.");
        }

        // Reuse the avatar re-encoder for shared EXIF-stripping discipline.
        return $this->reencoder->upload($creator, $file);
    }

    /**
     * Initiate a presigned S3 upload. Returns the presigned URL + the
     * planned object path; the client PUTs to the URL and then calls
     * complete() with the upload-id (which is the planned path).
     *
     * @return array{url: string, upload_id: string, expires_at: string, max_bytes: int}
     */
    public function initiatePresignedUpload(
        Creator $creator,
        string $mimeType,
        int $declaredBytes,
    ): array {
        if (! array_key_exists($mimeType, self::ACCEPTED_VIDEO_MIME_TYPES)) {
            throw new RuntimeException("Unsupported video MIME type: {$mimeType}.");
        }

        if ($declaredBytes > self::MAX_PRESIGNED_BYTES) {
            throw new RuntimeException('Declared file size exceeds 500MB.');
        }

        $extension = self::ACCEPTED_VIDEO_MIME_TYPES[$mimeType];
        $path = sprintf(
            'creators/%s/portfolio/%s.%s',
            $creator->ulid,
            (string) Str::ulid(),
            $extension,
        );

        $disk = Storage::disk('media');
        if (! $disk instanceof AwsS3V3Adapter) {
            // Local-dev fallback when running against the local filesystem
            // driver (test environment). The presigned URL is synthetic
            // but the upload_id remains a valid path the complete()
            // endpoint can write to.
            return [
                'url' => '/_test/presigned-upload/'.urlencode($path),
                'upload_id' => $path,
                'expires_at' => now()->addMinutes(15)->toIso8601String(),
                'max_bytes' => self::MAX_PRESIGNED_BYTES,
            ];
        }

        /** @var S3Client $client */
        $client = $disk->getClient();
        $command = $client->getCommand('PutObject', [
            'Bucket' => $disk->getConfig()['bucket'],
            'Key' => $path,
            'ContentType' => $mimeType,
            'ContentLength' => $declaredBytes,
        ]);

        $request = $client->createPresignedRequest($command, '+15 minutes');

        return [
            'url' => (string) $request->getUri(),
            'upload_id' => $path,
            'expires_at' => now()->addMinutes(15)->toIso8601String(),
            'max_bytes' => self::MAX_PRESIGNED_BYTES,
        ];
    }

    /**
     * Verify the presigned upload landed (object exists at the planned
     * path) and return the path. Caller is responsible for creating
     * the creator_portfolio_items row referencing this path.
     */
    public function completePresignedUpload(Creator $creator, string $uploadId): string
    {
        // upload_id is the planned path; sanity-check that it's scoped
        // under the creator's prefix to prevent cross-creator path
        // injection.
        $expectedPrefix = sprintf('creators/%s/portfolio/', $creator->ulid);
        if (! str_starts_with($uploadId, $expectedPrefix)) {
            throw new RuntimeException('Upload ID does not belong to this creator.');
        }

        $disk = Storage::disk('media');
        if (! $disk->exists($uploadId)) {
            throw new RuntimeException('Uploaded object not found at the expected path.');
        }

        return $uploadId;
    }
}
