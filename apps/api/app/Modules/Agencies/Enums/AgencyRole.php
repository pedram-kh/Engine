<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Enums;

/**
 * Roles a user can hold within an agency. Persisted on
 * agency_users.role (docs/03-DATA-MODEL.md §3). The full Phase 1 set:
 *
 *   - agency_admin   — full agency control, billing, user management
 *   - agency_manager — campaigns + creators; no billing or user management
 *   - agency_staff   — execute campaigns; cannot create brands or users
 *
 * See docs/20-PHASE-1-SPEC.md §4.2 for behavioral expectations.
 */
enum AgencyRole: string
{
    case AgencyAdmin = 'agency_admin';
    case AgencyManager = 'agency_manager';
    case AgencyStaff = 'agency_staff';
}
