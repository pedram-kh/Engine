<?php

declare(strict_types=1);

namespace App\Modules\Creators\Enums;

use App\Modules\Creators\Jobs\ProcessPortfolioImageJob;

/**
 * Asset-processing state of a portfolio item (ad-hoc AH-004 Q2).
 *
 *   - processing: a large image was uploaded via the presigned-PUT path and is
 *                 awaiting {@see ProcessPortfolioImageJob}
 *                 (EXIF strip + thumbnail). Its signed `view_url` /
 *                 `thumbnail_view_url` are WITHHELD by every portfolio resource
 *                 until this flips to `ready` — the raw, EXIF-bearing object must
 *                 never be reachable.
 *   - ready:      the asset is sanitized and serveable. Link + video items (and
 *                 every pre-AH-004 row) are `ready` from creation; large images
 *                 become `ready` only after the worker succeeds.
 *   - failed:     the worker rejected the upload (megapixel-guard trip on a
 *                 decompression bomb, or a corrupt/undecodable file). The item is
 *                 kept so the creator can SEE it and delete / re-upload — never a
 *                 silent forever-`processing`. A `failed` item is never serveable
 *                 or downloadable, and deleting it cleans up its raw S3 object.
 *
 * Stored as varchar on creator_portfolio_items.processing_status (default
 * `ready`). See docs/reviews/ah-004-portfolio-overhaul-plan.md §2/§5.
 */
enum PortfolioProcessingStatus: string
{
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
}
