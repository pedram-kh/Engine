<?php

declare(strict_types=1);

namespace App\Modules\Identity\Enums;

/**
 * The kind of principal a User row represents.
 *
 * Stored as a string in users.type. See docs/03-DATA-MODEL.md §2 for the
 * canonical column definition. `BrandUser` is reserved for Phase 2 per
 * docs/20-PHASE-1-SPEC.md §3 — Phase 1 never assigns it but the enum
 * carries it so the schema and code agree on the eventual full set.
 */
enum UserType: string
{
    case Creator = 'creator';
    case AgencyUser = 'agency_user';
    case BrandUser = 'brand_user';
    case PlatformAdmin = 'platform_admin';
}
