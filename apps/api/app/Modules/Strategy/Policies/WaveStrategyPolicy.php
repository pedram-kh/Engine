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
 *   manageCreators  admin, manager, staff (PL-3.1, P12): add, edit, replace and remove a wave's creators; the picker
 *   decideCreators  admin, manager only (PL-3.1, S4): record the client's decision on a creator
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

    public function manageCreators(User $user): bool
    {
        return CurrentAgencyRole::for($user) !== null;
    }

    public function decideCreators(User $user): bool
    {
        return CurrentAgencyRole::canManage($user);
    }

    /** PL-3.2a: write scripts (create, edit the brief and hooks, archive and restore): every agency role. */
    public function manageScripts(User $user): bool
    {
        return CurrentAgencyRole::for($user) !== null;
    }

    /** PL-3.2a: delete scripts for good (only from the archive, or together with a removed creator): admin and manager only. */
    public function deleteScriptsInUse(User $user): bool
    {
        return CurrentAgencyRole::canManage($user);
    }
}
