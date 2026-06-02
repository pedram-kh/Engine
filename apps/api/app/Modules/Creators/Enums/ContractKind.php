<?php

declare(strict_types=1);

namespace App\Modules\Creators\Enums;

/**
 * The kind of agreement a `contracts` row represents
 * (docs/03-DATA-MODEL.md §8, `:579`).
 *
 *   master_universal → the global Engine C master creator agreement
 *                      (agency_id null). The flag-OFF click-through accept
 *                      records one of these.
 *   master_agency    → an agency-specific master agreement.
 *   per_campaign     → a per-campaign addendum attached to a
 *                      campaign_assignment subject.
 */
enum ContractKind: string
{
    case MasterUniversal = 'master_universal';
    case MasterAgency = 'master_agency';
    case PerCampaign = 'per_campaign';
}
