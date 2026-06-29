<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Modules\Creators\Services\PortfolioImageProcessor;
use App\Modules\Messaging\Models\RelationshipThread;
use Aws\S3\S3Client;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Presigned-S3 uploads for relationship-message attachments (AH-010a, D4).
 *
 * The relationship analogue of {@see MessageAttachmentUploadService}: it copies
 * the thread-keyed presign/complete MECHANICS but is keyed on the
 * RELATIONSHIP thread prefix:
 *
 *   relationship-messages/{thread_ulid}/{file_ulid}.{ext}
 *
 * Same policy as campaign messaging (D4): images + video + PDF + common docs,
 * ≤25 MB each, ≤10 per message. The thread-keyed prefix is the isolation
 * backstop — a completed upload_id must sit under the resolved thread's own
 * prefix.
 *
 * DIVERGENCE from campaign messaging (D4, deliberate): image attachments are
 * EXIF-stripped on the way in via {@see self::sanitizeImageInPlace()} — reusing
 * AH-004's {@see PortfolioImageProcessor} (50 MP decompression-bomb guard +
 * full-res re-encode that drops GPS/EXIF). This runs SYNCHRONOUSLY at send time,
 * BEFORE the message row or any signed GET URL exists (race-free, no new
 * processing-state column). We do NOT propagate campaign messaging's
 * raw-store-EXIF behaviour onto this new surface. Virus scanning is out of scope
 * (a platform-wide gap; tech-debt).
 */
final class RelationshipMessageAttachmentUploadService
{
    public const int MAX_BYTES = 25 * 1024 * 1024; // 25MB per file (D4).

    public const int MAX_ATTACHMENTS_PER_MESSAGE = 10;

    /**
     * Accepted MIME → extension. Identical to campaign messaging's allowlist.
     *
     * @var array<string, string>
     */
    public const array ACCEPTED_MIME_TYPES = [
        // Images.
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        // Video.
        'video/mp4' => 'mp4',
        'video/quicktime' => 'mov',
        'video/webm' => 'webm',
        // PDF + common documents.
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
    ];

    public function __construct(private readonly PortfolioImageProcessor $imageProcessor) {}

    /**
     * Initiate a presigned S3 PUT scoped to the relationship thread's prefix.
     *
     * @return array{upload_url: string, upload_id: string, storage_path: string, expires_at: string, max_bytes: int}
     */
    public function initiatePresignedUpload(RelationshipThread $thread, string $mimeType, int $declaredBytes): array
    {
        if (! array_key_exists($mimeType, self::ACCEPTED_MIME_TYPES)) {
            throw new RuntimeException("Unsupported attachment MIME type: {$mimeType}.");
        }

        if ($declaredBytes > self::MAX_BYTES) {
            throw new RuntimeException('Declared file size exceeds 25MB.');
        }

        $extension = self::ACCEPTED_MIME_TYPES[$mimeType];
        $path = sprintf('relationship-messages/%s/%s.%s', $thread->ulid, (string) Str::ulid(), $extension);

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
     * Verify a presigned upload landed under THIS thread's prefix and return the
     * path. The prefix check rejects a path not scoped under
     * `relationship-messages/{thread_ulid}/`, so a sender can neither inject
     * another thread's object nor escape the namespace.
     */
    public function completePresignedUpload(RelationshipThread $thread, string $uploadId): string
    {
        $expectedPrefix = sprintf('relationship-messages/%s/', $thread->ulid);
        if (! str_starts_with($uploadId, $expectedPrefix)) {
            throw new RuntimeException('Upload ID does not belong to this thread.');
        }

        $disk = Storage::disk('media');
        if (! $disk->exists($uploadId)) {
            throw new RuntimeException('Uploaded object not found at the expected path.');
        }

        return $uploadId;
    }

    /**
     * If the stored object is a raster photo format the sanitiser supports
     * (jpeg / png / webp), re-encode it in place to strip EXIF/GPS (AH-004's
     * discipline). Runs synchronously at SEND time, before the message row or
     * any signed URL exists. A non-image (video / pdf / doc) or a `gif` — which
     * the processor does not handle and which is not a GPS-bearing photo format
     * — is left untouched.
     *
     * @throws RuntimeException when the declared-image bytes are undecodable or
     *                          exceed the 50 MP guard (surfaced as a clean 422
     *                          composer error, never a 500).
     */
    public function sanitizeImageInPlace(string $path, string $mimeType): void
    {
        $extension = $this->imageProcessor->extensionForMime($mimeType);
        if ($extension === null) {
            // Not a sanitiser-supported image (video / pdf / doc / gif) — store
            // as-is. (Non-image metadata stripping is out of scope, tech-debt.)
            return;
        }

        $disk = Storage::disk('media');
        $raw = $disk->get($path);
        if ($raw === null) {
            throw new RuntimeException('Uploaded image could not be read for sanitisation.');
        }

        // process() enforces the 50 MP decompression-bomb guard and re-encodes
        // the full-res image (the encode-from-decoded-pixels flow drops EXIF).
        $result = $this->imageProcessor->process($raw, $extension);

        $disk->put($path, $result['full']);
    }
}
