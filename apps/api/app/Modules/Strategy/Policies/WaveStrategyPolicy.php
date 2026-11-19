<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Policies;

use App\Core\Tenancy\CurrentAgencyRole;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * PL-2.1 strategy roles (plan section 3.3, ruling S6). The role is read from the agency of the CURRENT request (see
 * {@see CurrentAgencyRole}). Registered for the USP, persona and angle models; every ability is a gate.
 *
 *   view            admin, manager, staff
 *   create, update  admin, manager, staff: USPs, personas, angles, audience title, reorder, copy
 *   delete          admin, manager, staff: a persona, an angle, a USP no angle uses
 *   deleteInUse     admin, manager only: a USP an angle uses (decided in the service, under the wave lock)
 */
final class WaveStrategyPolicy
{
    use HandlesAuthorization;

    public function view(User $user): bool
    {
        return CurrentAgencyRole::for($user) !== null;
    }

    public function create(User $user): bool
    {
        return CurrentAgencyRole::for($user) !== null;
    }

    public function update(User $user): bool
    {
        return CurrentAgencyRole::for($user) !== null;
    }

    public function delete(User $user): bool
    {
        return CurrentAgencyRole::for($user) !== null;
    }

    public function deleteInUse(User $user): bool
    {
        return CurrentAgencyRole::canManage($user);
    }
}
