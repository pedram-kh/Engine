<?php

declare(strict_types=1);

namespace App\Modules\Creators\Services;

use App\Modules\Creators\Jobs\ProcessPortfolioImageJob;
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

    // AH-004 D8: 10 → 30 files/creator, uniform 500 MB ceiling for ALL file
    // types (images now join the presigned-PUT path video already proved).
    public const int MAX_ITEMS_PER_CREATOR = 30;

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
     * Key names mirror the published `PortfolioVideoInitResponse` type in
     *
     * @catalyst/api-client (the SPA reads `upload_url`) — see the
     * contract-guard test in CreatorWizardEndpointsTest.
     *
     * The `$namespace` partitions the per-creator key prefix
     * (`creators/{ulid}/{namespace}/…`). It defaults to `portfolio` (the
     * original caller — no behaviour change); the Sprint 9 draft-media flow
     * passes `drafts` so submission media lands under its own prefix and the
     * accepted-MIME set widens to include images (a draft post may be an
     * image, not just video — D-8). This is the reuse-not-duplicate path for
     * the presigned S3 mechanics.
     *
     * @return array{upload_url: string, upload_id: string, storage_path: string, expires_at: string, max_bytes: int}
     */
    public function initiatePresignedUpload(
        Creator $creator,
        string $mimeType,
        int $declaredBytes,
        string $namespace = 'portfolio',
    ): array {
        // Preserve the original 'video' wording for the portfolio namespace
        // (its contract test pins the message) — drafts use a namespace-
        // accurate label since they also accept images.
        $label = $namespace === 'drafts' ? 'draft' : 'video';

        return $this->presign(
            $creator,
            $mimeType,
            $declaredBytes,
            $namespace,
            $this->acceptedMimeTypesFor($namespace),
            $label,
        );
    }

    /**
     * Initiate a presigned upload for a large portfolio IMAGE (ad-hoc AH-004
     * Q5/D8). Same single-PUT mechanics + uniform 500 MB ceiling as video; the
     * object lands under the `portfolio` prefix and is sanitised asynchronously
     * by {@see ProcessPortfolioImageJob} after
     * complete().
     *
     * @return array{upload_url: string, upload_id: string, storage_path: string, expires_at: string, max_bytes: int}
     */
    public function initiatePresignedImageUpload(
        Creator $creator,
        string $mimeType,
        int $declaredBytes,
    ): array {
        return $this->presign(
            $creator,
            $mimeType,
            $declaredBytes,
            'portfolio',
            self::ACCEPTED_IMAGE_MIME_TYPES,
            'image',
        );
    }

    /**
     * Verify the presigned upload landed (object exists at the planned
     * path) and return the path. Caller is responsible for creating
     * the creator_portfolio_items row referencing this path.
     *
     * `$namespace` must match the value passed to
     * {@see initiatePresignedUpload()} — the prefix check rejects a path that
     * is not scoped under `creators/{ulid}/{namespace}/`, so a creator can
     * neither inject another creator's path nor cross namespaces.
     */
    public function completePresignedUpload(Creator $creator, string $uploadId, string $namespace = 'portfolio'): string
    {
        // upload_id is the planned path; sanity-check that it's scoped
        // under the creator's prefix to prevent cross-creator path
        // injection.
        $expectedPrefix = sprintf('creators/%s/%s/', $creator->ulid, $namespace);
        if (! str_starts_with($uploadId, $expectedPrefix)) {
            throw new RuntimeException('Upload ID does not belong to this creator.');
        }

        $disk = Storage::disk('media');
        if (! $disk->exists($uploadId)) {
            throw new RuntimeException('Uploaded object not found at the expected path.');
        }

        return $uploadId;
    }

    /**
     * Delete stored objects for a removed portfolio item (AH-004). Cleans up
     * the source AND thumbnail keys so a deleted item — including a `failed`
     * one whose raw upload is unreachable behind the resource gate — never
     * lingers as orphaned S3 storage. Null/blank paths (e.g. link items) are
     * skipped.
     */
    public function deleteStoredObjects(?string ...$paths): void
    {
        $disk = Storage::disk('media');

        foreach ($paths as $path) {
            if (is_string($path) && $path !== '') {
                $disk->delete($path);
            }
        }
    }

    /**
     * Shared presigned-PUT mechanics. Validates the MIME against the supplied
     * accepted set + the 500 MB ceiling, plans a per-creator-scoped key under
     * `creators/{ulid}/{namespace}/`, and returns the presigned URL + upload-id.
     *
     * @param  array<string, string>  $accepted  MIME → extension map.
     * @return array{upload_url: string, upload_id: string, storage_path: string, expires_at: string, max_bytes: int}
     */
    private function presign(
        Creator $creator,
        string $mimeType,
        int $declaredBytes,
        string $namespace,
        array $accepted,
        string $label,
    ): array {
        if (! array_key_exists($mimeType, $accepted)) {
            throw new RuntimeException("Unsupported {$label} MIME type: {$mimeType}.");
        }

        if ($declaredBytes > self::MAX_PRESIGNED_BYTES) {
            throw new RuntimeException('Declared file size exceeds 500MB.');
        }

        $extension = $accepted[$mimeType];
        $path = sprintf(
            'creators/%s/%s/%s.%s',
            $creator->ulid,
            $namespace,
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
                'upload_url' => '/_test/presigned-upload/'.urlencode($path),
                'upload_id' => $path,
                'storage_path' => $path,
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
            'upload_url' => (string) $request->getUri(),
            'upload_id' => $path,
            'storage_path' => $path,
            'expires_at' => now()->addMinutes(15)->toIso8601String(),
            'max_bytes' => self::MAX_PRESIGNED_BYTES,
        ];
    }

    /**
     * The accepted MIME → extension map for a given upload namespace. The
     * `drafts` namespace accepts both images and videos (a draft post may be
     * an image); every other namespace (portfolio) stays video-only.
     *
     * @return array<string, string>
     */
    private function acceptedMimeTypesFor(string $namespace): array
    {
        return $namespace === 'drafts'
            ? array_merge(self::ACCEPTED_IMAGE_MIME_TYPES, self::ACCEPTED_VIDEO_MIME_TYPES)
            : self::ACCEPTED_VIDEO_MIME_TYPES;
    }
}
