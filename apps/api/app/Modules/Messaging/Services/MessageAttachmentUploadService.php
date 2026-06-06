<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Modules\Creators\Services\PortfolioUploadService;
use App\Modules\Messaging\Models\MessageThread;
use Aws\S3\S3Client;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Presigned-S3 uploads for message attachments (Sprint 11, D-6).
 *
 * A PARALLEL uploader to {@see PortfolioUploadService}
 * (the contract-bridge "mirror, don't generalize" precedent) — it copies the
 * presign/complete MECHANICS but is THREAD-keyed, not creator-keyed:
 *
 *   messages/{thread_ulid}/{file_ulid}.{ext}
 *
 * Policy (D-6, adjustable): images + video + PDF + common docs, ≤25 MB each,
 * ≤10 per message. The thread-keyed prefix is the isolation backstop — a
 * completed upload_id must sit under the resolved thread's own prefix, so one
 * thread's send can never reference another thread's object.
 */
final class MessageAttachmentUploadService
{
    public const int MAX_BYTES = 25 * 1024 * 1024; // 25MB per file (D-6).

    public const int MAX_ATTACHMENTS_PER_MESSAGE = 10;

    /**
     * Accepted MIME → extension. Images + video + PDF + common office/text docs.
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

    /**
     * Initiate a presigned S3 PUT scoped to the thread's own prefix. The client
     * PUTs the bytes with the EXACT signed Content-Type, then completes (or
     * sends) with the returned `upload_id` (which is the planned path).
     *
     * @return array{upload_url: string, upload_id: string, storage_path: string, expires_at: string, max_bytes: int}
     */
    public function initiatePresignedUpload(MessageThread $thread, string $mimeType, int $declaredBytes): array
    {
        if (! array_key_exists($mimeType, self::ACCEPTED_MIME_TYPES)) {
            throw new RuntimeException("Unsupported attachment MIME type: {$mimeType}.");
        }

        if ($declaredBytes > self::MAX_BYTES) {
            throw new RuntimeException('Declared file size exceeds 25MB.');
        }

        $extension = self::ACCEPTED_MIME_TYPES[$mimeType];
        $path = sprintf('messages/%s/%s.%s', $thread->ulid, (string) Str::ulid(), $extension);

        $disk = Storage::disk('media');
        if (! $disk instanceof AwsS3V3Adapter) {
            // Local-dev / test fallback (the PortfolioUploadService precedent):
            // a synthetic URL, but the upload_id remains a real path complete()
            // can verify against.
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
     * path. The prefix check rejects a path that is not scoped under
     * `messages/{thread_ulid}/`, so a sender can neither inject another thread's
     * object nor escape the namespace.
     */
    public function completePresignedUpload(MessageThread $thread, string $uploadId): string
    {
        $expectedPrefix = sprintf('messages/%s/', $thread->ulid);
        if (! str_starts_with($uploadId, $expectedPrefix)) {
            throw new RuntimeException('Upload ID does not belong to this thread.');
        }

        $disk = Storage::disk('media');
        if (! $disk->exists($uploadId)) {
            throw new RuntimeException('Uploaded object not found at the expected path.');
        }

        return $uploadId;
    }
}
