<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Enums;

/**
 * PL-1.2 — what kind of campaign this is. `normal` is every campaign that existed before PL-1.2 and the default.
 * `for_wave` is a campaign made for a wave: creators do not post content, and its board is the nine-column wave
 * board. Set on create and immutable after (`campaign.type_immutable`).
 */
enum CampaignType: string
{
    case Normal = 'normal';
    case ForWave = 'for_wave';
}
