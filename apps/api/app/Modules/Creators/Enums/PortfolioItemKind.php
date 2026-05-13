<?php

declare(strict_types=1);

namespace App\Modules\Creators\Enums;

/**
 * Kind of portfolio item.
 *
 *   - video: uploaded video file (s3_path + thumbnail_path populated).
 *   - image: uploaded image file (s3_path populated; thumbnail_path
 *            optional — frontend may use the source for thumbnails).
 *   - link:  external URL (external_url populated; s3_path null).
 *
 * Stored as varchar(16) on creator_portfolio_items.kind. See
 * docs/03-DATA-MODEL.md §5.
 */
enum PortfolioItemKind: string
{
    case Video = 'video';
    case Image = 'image';
    case Link = 'link';
}
