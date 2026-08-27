<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Enums;

/**
 * Organizing category for an `AgencyFile` row. AH-091 (Agency Files library
 * chunk A) ships exactly one value; chunk B is expected to attach files of
 * this category to campaigns. Persisted as a plain `varchar(32)` column
 * (not a DB enum) — see the migration docblock for why.
 */
enum AgencyFileCategory: string
{
    case CampaignAttachment = 'campaign_attachment';
}
