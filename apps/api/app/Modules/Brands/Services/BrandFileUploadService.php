<?php

declare(strict_types=1);

namespace App\Modules\Brands\Services;

use App\Core\Storage\AttachmentMimeTypes;
use App\Core\Storage\StorageDeleteFailedException;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Services\AgencyFileUploadService;
use App\Modules\Brands\Models\Brand;
use App\Modules\Brands\Models\BrandFile;
use Aws\S3\S3Client;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Presigned upload, signed download and object-first delete for the client files of a brand (PL-1.1). The
 * mechanics are those of {@see AgencyFileUploadService} (its docblock explains the verification posture) with a
 * brand-keyed path and these differences:
 *
 *   agencies/{agency_ulid}/brands/{brand_ulid}/files/{file_ulid}.{ext}
 *
 *   - cap 100 MB (the Client files drop zone's text), one presigned PUT, no multipart;
 *   - MIME set = {@see AttachmentMimeTypes::NON_VIDEO} plus ZIP (kept here; `AttachmentMimeTypes` is not edited,
 *     its `ALL` is pinned byte for byte). No video, no SVG, no executables;
 *   - downloads are signed GETs forced to `Content-Disposition: attachment`, never inline.
 *
 * NO VIRUS SCAN and no magic-byte check: the same as every upload surface today. Accepted only while agency staff
 * upload; MUST be solved before PL-4, when clients upload (docs/tech-debt.md row 40).
 */
final class BrandFileUploadService
{
    public const int MAX_BYTES = 100 * 1024 * 1024;

    /** @var array<string, string> mime => extension */
    public const array ALLOWED_MIME_TYPES = AttachmentMimeTypes::NON_VIDEO + [
        'application/zip' => 'zip',
        'application/x-zip-compressed' => 'zip',
    ];

    private const int SIGNED_URL_TTL_MINUTES = 60;

    /**
     * @return array{upload_url: string, upload_id: string, storage_path: string, expires_at: string, max_bytes: int}
     */
    public function initiatePresignedUpload(Agency $agency, Brand $brand, string $mimeType, int $declaredBytes): array
    {
        if (! array_key_exists($mimeType, self::ALLOWED_MIME_TYPES)) {
            throw new RuntimeException("Unsupported file MIME type: {$mimeType}.");
        }

        if ($declaredBytes < 1) {
            throw new RuntimeException('Declared file size must be at least 1 byte.');
        }

        if ($declaredBytes > self::MAX_BYTES) {
            throw new RuntimeException('Declared file size exceeds 100MB.');
        }

        $path = sprintf(
            '%s%s.%s',
            self::prefix($agency, $brand),
            (string) Str::ulid(),
            self::ALLOWED_MIME_TYPES[$mimeType],
        );

        $disk = Storage::disk('media');
        if (! $disk instanceof AwsS3V3Adapter) {
            // Local-dev / test fallback (the AgencyFileUploadService precedent).
            return [
                'upload_url' => '/_test/presigned-upload/'.urlencode($path),
                'upload_id' => $path,
                'storage_path' => $path,
                'expires_at' => now()->addMinutes(15)->toIso8601String(),
                'max_bytes' => self::MAX_BYTES,
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
            'max_bytes' => self::MAX_BYTES,
        ];
    }

    /**
     * Verify a presigned upload landed under THIS brand's `files/` prefix (agency and brand both in the key), then
     * re-derive MIME and size from the server-observed object, never from anything the client declared.
     *
     * @return array{storage_key: string, mime_type: string, size_bytes: int}
     */
    public function completePresignedUpload(Agency $agency, Brand $brand, string $uploadId): array
    {
        if (! str_starts_with($uploadId, self::prefix($agency, $brand))) {
            throw new RuntimeException('Upload ID does not belong to this brand.');
        }

        $disk = Storage::disk('media');
        if (! $disk->exists($uploadId)) {
            throw new RuntimeException('Uploaded object not found at the expected path.');
        }

        $extension = strtolower(pathinfo($uploadId, PATHINFO_EXTENSION));
        $mimeType = $this->mimeForExtension($extension);
        if ($mimeType === null) {
            throw new RuntimeException('Uploaded object has an unrecognized extension.');
        }

        $sizeBytes = $disk->size($uploadId);
        if ($sizeBytes <= 0) {
            throw new RuntimeException('Uploaded object is empty.');
        }

        if ($sizeBytes > self::MAX_BYTES) {
            throw new RuntimeException('Uploaded object exceeds the 100MB limit.');
        }

        return [
            'storage_key' => $uploadId,
            'mime_type' => $mimeType,
            'size_bytes' => $sizeBytes,
        ];
    }

    /**
     * A signed GET that forces `Content-Disposition: attachment`. Null on a non-S3 disk (test fakes).
     */
    public function signedDownloadUrl(BrandFile $file): ?string
    {
        $disk = Storage::disk('media');
        if (! $disk instanceof AwsS3V3Adapter) {
            return null;
        }

        return $disk->temporaryUrl(
            $file->storage_key,
            now()->addMinutes(self::SIGNED_URL_TTL_MINUTES),
            ['ResponseContentDisposition' => 'attachment; filename="'.$this->downloadFilename($file).'"'],
        );
    }

    /**
     * Object-first hard delete. Re-asserts the brand's own prefix, then throws
     * {@see StorageDeleteFailedException} on anything but a confirmed `true`, so the caller never soft-deletes the
     * row over a delete that did not happen.
     */
    public function delete(Agency $agency, Brand $brand, BrandFile $file): void
    {
        if (! str_starts_with($file->storage_key, self::prefix($agency, $brand))) {
            throw new RuntimeException('Storage key does not belong to this brand.');
        }

        $disk = Storage::disk('media');
        if ($disk->delete($file->storage_key) !== true) {
            throw StorageDeleteFailedException::forPath('media', $file->storage_key);
        }
    }

    public static function prefix(Agency $agency, Brand $brand): string
    {
        return sprintf('agencies/%s/brands/%s/files/', $agency->ulid, $brand->ulid);
    }

    private function mimeForExtension(string $extension): ?string
    {
        foreach (self::ALLOWED_MIME_TYPES as $mime => $ext) {
            if ($ext === $extension) {
                return $mime;
            }
        }

        return null;
    }

    /** A safe download filename: the sanitized base of the original name plus the SERVER-derived extension. */
    private function downloadFilename(BrandFile $file): string
    {
        $extension = pathinfo($file->storage_key, PATHINFO_EXTENSION);
        $originalBase = pathinfo($file->original_filename, PATHINFO_FILENAME);
        $base = $originalBase !== ''
            ? (string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $originalBase)
            : 'file-'.$file->ulid;
        $base = trim($base, '-') ?: 'file-'.$file->ulid;

        return $extension !== '' ? $base.'.'.$extension : $base;
    }
}
