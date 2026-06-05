<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Services;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Campaigns\Models\CampaignAssignment;
use Aws\S3\S3Client;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Agency-scoped presigned PDF upload for per-campaign contract attachments
 * (contract-bridge chunk, D-9). Mirrors the presigned mechanics of
 * {@see PortfolioUploadService} but with agency/assignment ownership — NOT
 * creator-keyed. Path shape:
 *
 *   agencies/{agency_ulid}/assignments/{assignment_ulid}/contracts/{file_ulid}.pdf
 */
final class AssignmentContractUploadService
{
    public const int MAX_PRESIGNED_BYTES = 10 * 1024 * 1024; // 10MB — PDF contracts

    /**
     * @var array<string, string>
     */
    public const array ACCEPTED_PDF_MIME_TYPES = [
        'application/pdf' => 'pdf',
    ];

    /**
     * @return array{upload_url: string, upload_id: string, storage_path: string, expires_at: string, max_bytes: int}
     */
    public function initiatePresignedUpload(
        Agency $agency,
        CampaignAssignment $assignment,
        string $mimeType,
        int $declaredBytes,
    ): array {
        if (! array_key_exists($mimeType, self::ACCEPTED_PDF_MIME_TYPES)) {
            throw new RuntimeException("Unsupported contract MIME type: {$mimeType}.");
        }

        if ($declaredBytes > self::MAX_PRESIGNED_BYTES) {
            throw new RuntimeException('Declared file size exceeds 10MB.');
        }

        $path = sprintf(
            'agencies/%s/assignments/%s/contracts/%s.pdf',
            $agency->ulid,
            $assignment->ulid,
            (string) Str::ulid(),
        );

        $disk = Storage::disk('media');
        if (! $disk instanceof AwsS3V3Adapter) {
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

    public function completePresignedUpload(
        Agency $agency,
        CampaignAssignment $assignment,
        string $uploadId,
    ): string {
        $expectedPrefix = sprintf(
            'agencies/%s/assignments/%s/contracts/',
            $agency->ulid,
            $assignment->ulid,
        );
        if (! str_starts_with($uploadId, $expectedPrefix)) {
            throw new RuntimeException('Upload ID does not belong to this assignment.');
        }

        $disk = Storage::disk('media');
        if (! $disk->exists($uploadId)) {
            throw new RuntimeException('Uploaded object not found at the expected path.');
        }

        return $uploadId;
    }
}
