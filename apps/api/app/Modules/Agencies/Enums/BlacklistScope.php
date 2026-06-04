<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Enums;

/**
 * The reach of a creator blacklist (Sprint 7, D-6).
 *
 *   agency → blacklisted across the ENTIRE agency. Lives ON the
 *            agency_creator_relations row (the six built columns). The SOLE
 *            scope that flips the relation's `is_blacklisted`/`blacklist_scope`
 *            (D-2) — and the only scope active in discovery + the request gate
 *            + the roster KPI counts today (Part B).
 *   brand  → blacklisted for ONE brand only, ok for the rest of the agency.
 *            Lives as a row in `brand_creator_blacklists` (D-2: NOT mirrored
 *            onto the relation). Recorded now; its matching/exclusion effect is
 *            Sprint 8 (campaign-level matching) — discovery is agency-level and
 *            has no brand context, so brand scope does NOT touch discovery or
 *            the agency KPI counts.
 *
 * Stored as varchar(8) on agency_creator_relations.blacklist_scope.
 */
enum BlacklistScope: string
{
    case Agency = 'agency';
    case Brand = 'brand';
}
