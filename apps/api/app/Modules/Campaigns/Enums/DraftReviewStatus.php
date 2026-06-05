<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Enums;

/**
 * The review status of a single `campaign_drafts` row (Sprint 9 Chunk 1, D-1).
 *
 * Per docs/03-DATA-MODEL.md §7 (`campaign_drafts.review_status`). Stored as
 * varchar(16). Chunk 1 only ever WRITES `pending` (the submission side); the
 * agency review (Chunk 2) advances it to `approved`, `rejected` or
 * `revision_requested`.
 */
enum DraftReviewStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case RevisionRequested = 'revision_requested';
}
