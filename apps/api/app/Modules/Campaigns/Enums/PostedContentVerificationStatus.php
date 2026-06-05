<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Enums;

/**
 * The verification status of a `campaign_posted_content` row (Sprint 9 Chunk 1,
 * D-2).
 *
 * Per docs/03-DATA-MODEL.md §7 (`campaign_posted_content.verification_status`).
 * Stored as varchar(16). Chunk 1 only ever WRITES `pending` (the creator
 * self-reports the post); Chunk 2's `VerifyPostedContentJob` advances it to
 * `verified`, `not_found` or `mismatch`.
 */
enum PostedContentVerificationStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case NotFound = 'not_found';
    case Mismatch = 'mismatch';
}
