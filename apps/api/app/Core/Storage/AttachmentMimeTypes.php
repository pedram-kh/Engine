<?php

declare(strict_types=1);

namespace App\Core\Storage;

use App\Modules\Campaigns\Services\AssignmentOfferAttachmentUploadService;
use App\Modules\Messaging\Services\MessageAttachmentUploadService;
use App\Modules\Messaging\Services\RelationshipMessageAttachmentUploadService;

/**
 * The shared "images + video + PDF/office docs" MIME → extension map used by
 * every presigned attachment uploader. Before AH-091 this exact 14-entry
 * array was copied byte-for-byte into THREE services —
 * {@see MessageAttachmentUploadService},
 * {@see RelationshipMessageAttachmentUploadService},
 * and {@see AssignmentOfferAttachmentUploadService}
 * — with no shared source of truth. This class extracts ONLY that map; the
 * three-call presign/complete flow itself stays duplicated across those
 * services (and this chunk's new fourth), which is a separate, larger
 * refactor named in tech-debt, not something this extraction attempts.
 *
 * `IMAGES`, `VIDEOS`, and `DOCUMENTS` are disjoint. `ALL` is byte-identical to
 * the three services' original inline arrays (same keys, same order, same
 * values) — a consumer swapping its own array for `ALL` changes nothing about
 * its accepted MIME set. `NON_VIDEO` is new: Agency Files (AH-091) is a
 * document library, not media storage, so it accepts everything in `ALL`
 * except `VIDEOS` (D3's subtraction).
 */
final class AttachmentMimeTypes
{
    /**
     * @var array<string, string>
     */
    public const array IMAGES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    /**
     * @var array<string, string>
     */
    public const array VIDEOS = [
        'video/mp4' => 'mp4',
        'video/quicktime' => 'mov',
        'video/webm' => 'webm',
    ];

    /**
     * @var array<string, string>
     */
    public const array DOCUMENTS = [
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
    ];

    /**
     * Byte-identical to the pre-AH-091 inline arrays in all three existing
     * consumers — same 14 keys, same order.
     *
     * @var array<string, string>
     */
    public const array ALL = self::IMAGES + self::VIDEOS + self::DOCUMENTS;

    /**
     * Agency Files' own accepted set (D3): documents, not media storage.
     *
     * @var array<string, string>
     */
    public const array NON_VIDEO = self::IMAGES + self::DOCUMENTS;
}
