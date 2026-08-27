<?php

declare(strict_types=1);

use App\Core\Storage\AttachmentMimeTypes;
use App\Modules\Campaigns\Services\AssignmentOfferAttachmentUploadService;
use App\Modules\Messaging\Services\MessageAttachmentUploadService;
use App\Modules\Messaging\Services\RelationshipMessageAttachmentUploadService;

it('pins ALL to the exact 14 pre-extraction entries, in order', function (): void {
    expect(AttachmentMimeTypes::ALL)->toBe([
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'video/mp4' => 'mp4',
        'video/quicktime' => 'mov',
        'video/webm' => 'webm',
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
    ])->and(AttachmentMimeTypes::ALL)->toHaveCount(14);
});

it('pins NON_VIDEO to ALL minus the three VIDEOS entries', function (): void {
    expect(AttachmentMimeTypes::NON_VIDEO)->toHaveCount(11)
        ->and(AttachmentMimeTypes::NON_VIDEO)->not->toHaveKey('video/mp4')
        ->and(AttachmentMimeTypes::NON_VIDEO)->not->toHaveKey('video/quicktime')
        ->and(AttachmentMimeTypes::NON_VIDEO)->not->toHaveKey('video/webm')
        ->and(array_diff_key(AttachmentMimeTypes::ALL, AttachmentMimeTypes::VIDEOS))
        ->toBe(AttachmentMimeTypes::NON_VIDEO);
});

it('IMAGES, VIDEOS, and DOCUMENTS are disjoint and sum to ALL', function (): void {
    $union = AttachmentMimeTypes::IMAGES + AttachmentMimeTypes::VIDEOS + AttachmentMimeTypes::DOCUMENTS;

    expect($union)->toBe(AttachmentMimeTypes::ALL)
        ->and(array_intersect_key(AttachmentMimeTypes::IMAGES, AttachmentMimeTypes::VIDEOS))->toBe([])
        ->and(array_intersect_key(AttachmentMimeTypes::IMAGES, AttachmentMimeTypes::DOCUMENTS))->toBe([])
        ->and(array_intersect_key(AttachmentMimeTypes::VIDEOS, AttachmentMimeTypes::DOCUMENTS))->toBe([]);
});

it('all three pre-existing consumers now import the shared ALL constant, byte-identically', function (): void {
    expect(MessageAttachmentUploadService::ACCEPTED_MIME_TYPES)->toBe(AttachmentMimeTypes::ALL)
        ->and(RelationshipMessageAttachmentUploadService::ACCEPTED_MIME_TYPES)->toBe(AttachmentMimeTypes::ALL)
        ->and(AssignmentOfferAttachmentUploadService::ACCEPTED_MIME_TYPES)->toBe(AttachmentMimeTypes::ALL);
});
