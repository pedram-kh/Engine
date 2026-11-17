<?php

declare(strict_types=1);

namespace App\Core\Tenancy;

use App\Modules\Agencies\Enums\AgencyRole;
use App\Modules\Agencies\Models\AgencyMembership;
use App\Modules\Identity\Models\User;

/**
 * The user's role in the agency of the CURRENT request (the TenancyContext agency, set by `tenancy.agency` from
 * the route), not "the user's first membership". PL-1 policies use this so a user who is staff in agency A and
 * admin in agency B is judged by the agency named in the URL. Null when there is no context or no accepted,
 * live membership in that agency.
 */
final class CurrentAgencyRole
{
    public static function for(User $user): ?AgencyRole
    {
        $agencyId = app(TenancyContext::class)->agencyId();
        if ($agencyId === null) {
            return null;
        }

        $membership = AgencyMembership::withoutGlobalScope(BelongsToAgencyScope::class)
            ->where('user_id', $user->id)
            ->where('agency_id', $agencyId)
            ->whereNotNull('accepted_at')
            ->whereNull('deleted_at')
            ->first();

        return $membership?->role;
    }

    public static function canManage(User $user): bool
    {
        $role = self::for($user);

        return $role === AgencyRole::AgencyAdmin || $role === AgencyRole::AgencyManager;
    }
}
