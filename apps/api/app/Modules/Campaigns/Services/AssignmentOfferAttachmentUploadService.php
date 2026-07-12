<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Services;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Creators\Services\PortfolioImageProcessor;
use Aws\S3\S3Client;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Presigned-S3 uploads for the invite-offer attachment (invite-offer-details
 * batch). Mirrors {@see RelationshipMessageAttachmentUploadService} mechanics
 * but is CAMPAIGN-keyed, because the upload happens BEFORE any assignment row
 * exists (the invite dialog uploads once, then the bulk loop stamps the same
 * path onto every created assignment):
 *
 *   agencies/{agency_ulid}/campaigns/{campaign_ulid}/offer-attachments/{file_ulid}.{ext}
 *
 * Same policy as messaging attachments: images + video + PDF + common docs,
 * ≤25 MB, MIME allowlist. The campaign-keyed prefix is the isolation backstop —
 * a completed upload_id must sit under the campaign's own prefix, so an invite
 * can neither inject another campaign's object nor escape the namespace.
 *
 * EXIF strip (the AH-010a discipline): supported raster images (jpeg/png/webp)
 * are re-encoded in place at COMPLETE time via {@see PortfolioImageProcessor}
 * (50 MP decompression-bomb guard, drops GPS/EXIF) — before any assignment row
 * or signed URL exists, and exactly once per upload regardless of how many
 * creators the invite loop fans out to. Virus scanning remains the recorded
 * platform-wide gap (tech-debt).
 */
final class AssignmentOfferAttachmentUploadService
{
    public const int MAX_BYTES = 25 * 1024 * 1024; // 25MB, the messaging-attachment ceiling.

    /**
     * Accepted MIME → extension. Identical to the messaging allowlist.
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

    private const int SIGNED_URL_TTL_MINUTES = 60;

    public function __construct(private readonly PortfolioImageProcessor $imageProcessor) {}

    /**
     * @return array{upload_url: string, upload_id: string, storage_path: string, expires_at: string, max_bytes: int}
     */
    public function initiatePresignedUpload(Agency $agency, Campaign $campaign, string $mimeType, int $declaredBytes): array
    {
        if (! array_key_exists($mimeType, self::ACCEPTED_MIME_TYPES)) {
            throw new RuntimeException("Unsupported attachment MIME type: {$mimeType}.");
        }

        if ($declaredBytes > self::MAX_BYTES) {
            throw new RuntimeException('Declared file size exceeds 25MB.');
        }

        $extension = self::ACCEPTED_MIME_TYPES[$mimeType];
        $path = sprintf(
            'agencies/%s/campaigns/%s/offer-attachments/%s.%s',
            $agency->ulid,
            $campaign->ulid,
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
     * Verify a presigned upload landed under THIS campaign's offer prefix,
     * EXIF-strip it in place when it is a supported raster image, and return
     * the path. Runs once at complete time — before any assignment row or
     * signed URL exists.
     */
    public function completePresignedUpload(Agency $agency, Campaign $campaign, string $uploadId): string
    {
        $this->assertUploadBelongs($agency, $campaign, $uploadId);

        $extension = strtolower(pathinfo($uploadId, PATHINFO_EXTENSION));
        if (in_array($extension, ['jpg', 'png', 'webp'], true)) {
            $disk = Storage::disk('media');
            $raw = $disk->get($uploadId);
            if ($raw === null) {
                throw new RuntimeException('Uploaded image could not be read for sanitisation.');
            }

            // process() enforces the 50 MP guard and re-encodes from decoded
            // pixels (which drops EXIF/GPS). Undecodable input surfaces as a
            // clean 422 in the controller, never a 500.
            $result = $this->imageProcessor->process($raw, $extension);
            $disk->put($uploadId, $result['full']);
        }

        return $uploadId;
    }

    /**
     * The isolation backstop, re-run at INVITE time for the upload_id carried
     * in the invite payload: prefix-scoped to this campaign + the object must
     * exist. Idempotent + cheap, so the bulk invite loop can call it per row.
     */
    public function assertUploadBelongs(Agency $agency, Campaign $campaign, string $uploadId): void
    {
        $expectedPrefix = sprintf(
            'agencies/%s/campaigns/%s/offer-attachments/',
            $agency->ulid,
            $campaign->ulid,
        );
        if (! str_starts_with($uploadId, $expectedPrefix)) {
            throw new RuntimeException('Upload ID does not belong to this campaign.');
        }

        if (! Storage::disk('media')->exists($uploadId)) {
            throw new RuntimeException('Uploaded object not found at the expected path.');
        }
    }

    /**
     * Short-lived signed GET for an offer attachment. Minted ONLY inside an
     * already-authorized resource emission, so the download inherits each
     * surface's view authz (the AH-004 posture). Null when there is no path or
     * the `media` disk is not S3 (Storage::fake in tests).
     */
    public static function signedViewUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        $disk = Storage::disk('media');
        if (! $disk instanceof AwsS3V3Adapter) {
            return null;
        }

        return $disk->temporaryUrl($path, now()->addMinutes(self::SIGNED_URL_TTL_MINUTES));
    }
}
