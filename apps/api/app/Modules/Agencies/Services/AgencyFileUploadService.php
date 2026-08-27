<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Services;

use App\Core\Storage\AttachmentMimeTypes;
use App\Core\Storage\StorageDeleteFailedException;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyFile;
use App\Modules\Campaigns\Services\AssignmentContractUploadService;
use App\Modules\Creators\Support\PortfolioItemPresenter;
use Aws\S3\S3Client;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Agency-scoped presigned document upload/download/delete for the Agency
 * Files library (AH-091, chunk A). Mirrors
 * {@see AssignmentContractUploadService}'s
 * mechanics (agency-keyed, document-oriented, NO re-encoding) rather than the
 * video-capable creator portfolio pipeline — this chunk's D2. Path shape:
 *
 *   agencies/{agency_ulid}/files/{file_ulid}.{ext}
 *
 * Policy (D3): {@see AttachmentMimeTypes::NON_VIDEO} (images + PDF/office
 * docs, no video — a document library, not media storage), ≤25 MB.
 *
 * Deliberate improvement on the template it copies (server-side metadata
 * verification, ratified at plan-pause): `completePresignedUpload()` never
 * re-trusts the client-declared MIME type past `initiatePresignedUpload()` —
 * it derives the MIME from the SERVER-CHOSEN extension on the landed object's
 * own key, and reads the object's ACTUAL byte size via `Storage::size()`,
 * rejecting an empty or over-cap landed object. A future presigned surface
 * copying this file inherits that verification for free.
 *
 * Delete (D5): object-first, mirroring the CreatorPortfolioItem precedent —
 * the caller (AgencyFileController) hard-deletes the S3 object via
 * {@see self::delete()} and ONLY on success soft-deletes the row. A
 * `Storage::delete()` failure throws {@see StorageDeleteFailedException}
 * rather than being swallowed, so the row is never soft-deleted over live,
 * now-orphaned bytes.
 */
final class AgencyFileUploadService
{
    public const int MAX_BYTES = 25 * 1024 * 1024; // 25MB — the offer/message-attachment ceiling (D3).

    private const int SIGNED_URL_TTL_MINUTES = 60;

    /**
     * @return array{upload_url: string, upload_id: string, storage_path: string, expires_at: string, max_bytes: int}
     */
    public function initiatePresignedUpload(Agency $agency, string $mimeType, int $declaredBytes): array
    {
        if (! array_key_exists($mimeType, AttachmentMimeTypes::NON_VIDEO)) {
            throw new RuntimeException("Unsupported file MIME type: {$mimeType}.");
        }

        if ($declaredBytes < 1) {
            throw new RuntimeException('Declared file size must be at least 1 byte.');
        }

        if ($declaredBytes > self::MAX_BYTES) {
            throw new RuntimeException('Declared file size exceeds 25MB.');
        }

        $extension = AttachmentMimeTypes::NON_VIDEO[$mimeType];
        $path = sprintf(
            'agencies/%s/files/%s.%s',
            $agency->ulid,
            (string) Str::ulid(),
            $extension,
        );

        $disk = Storage::disk('media');
        if (! $disk instanceof AwsS3V3Adapter) {
            // Local-dev / test fallback (the PortfolioUploadService precedent).
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
     * Verify a presigned upload landed under THIS agency's `files/` prefix,
     * then re-derive its MIME and size from the server-observed object
     * itself — never from anything the client declared at init time.
     *
     * @return array{storage_key: string, mime_type: string, size_bytes: int}
     */
    public function completePresignedUpload(Agency $agency, string $uploadId): array
    {
        $expectedPrefix = sprintf('agencies/%s/files/', $agency->ulid);
        if (! str_starts_with($uploadId, $expectedPrefix)) {
            throw new RuntimeException('Upload ID does not belong to this agency.');
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
            throw new RuntimeException('Uploaded object exceeds the 25MB limit.');
        }

        return [
            'storage_key' => $uploadId,
            'mime_type' => $mimeType,
            'size_bytes' => $sizeBytes,
        ];
    }

    /**
     * Mint a presigned GET that forces `Content-Disposition: attachment`
     * (D4) — the {@see PortfolioItemPresenter}
     * precedent. Returns null on a non-S3 disk (test fakes / local dev
     * without MinIO reachable for a real presign — the same guard every
     * signed-read helper in this codebase uses).
     */
    public function signedDownloadUrl(AgencyFile $file): ?string
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
     * Object-first hard delete (D5). Re-asserts the agency's own prefix
     * (defense in depth — the caller has already checked
     * `$file->agency_id === $agency->id`), then throws
     * {@see StorageDeleteFailedException} on anything but a confirmed
     * `true` from the disk, so the caller never proceeds to soft-delete the
     * row over a delete that did not actually happen.
     */
    public function delete(Agency $agency, AgencyFile $file): void
    {
        $expectedPrefix = sprintf('agencies/%s/files/', $agency->ulid);
        if (! str_starts_with($file->storage_key, $expectedPrefix)) {
            throw new RuntimeException('Storage key does not belong to this agency.');
        }

        $disk = Storage::disk('media');
        if ($disk->delete($file->storage_key) !== true) {
            throw StorageDeleteFailedException::forPath('media', $file->storage_key);
        }
    }

    private function mimeForExtension(string $extension): ?string
    {
        foreach (AttachmentMimeTypes::NON_VIDEO as $mime => $ext) {
            if ($ext === $extension) {
                return $mime;
            }
        }

        return null;
    }

    /**
     * A safe download filename: the sanitized original filename's base
     * (alnum/dash/underscore only, extension stripped) or a ULID fallback,
     * suffixed with the SERVER-derived extension from the storage key —
     * never the client-supplied `original_filename`'s own extension.
     */
    private function downloadFilename(AgencyFile $file): string
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
