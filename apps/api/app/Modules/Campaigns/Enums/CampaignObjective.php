<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Enums;

/**
 * The goal of a Campaign (Sprint 8 Chunk 1, D-1).
 *
 * Per docs/03-DATA-MODEL.md §7. Stored as varchar(32) on
 * `campaigns.objective`.
 */
enum CampaignObjective: string
{
    case Awareness = 'awareness';
    case Engagement = 'engagement';
    case Conversion = 'conversion';
    case Ugc = 'ugc';
    case Launch = 'launch';
}
