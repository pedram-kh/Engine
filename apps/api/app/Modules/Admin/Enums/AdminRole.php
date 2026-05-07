<?php

declare(strict_types=1);

namespace App\Modules\Admin\Enums;

/**
 * Catalyst Engine ops-staff roles. Persisted on admin_profiles.admin_role
 * (docs/03-DATA-MODEL.md §13). Phase 1 only uses `super_admin` per
 * docs/20-PHASE-1-SPEC.md §4.3; the remaining cases are reserved so
 * Phase 2's role expansion does not require a schema change.
 */
enum AdminRole: string
{
    case SuperAdmin = 'super_admin';
    case Support = 'support';
    case Finance = 'finance';
    case Security = 'security';
}
