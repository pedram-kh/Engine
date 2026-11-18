<?php

declare(strict_types=1);

namespace App\Modules\Waves\Policies;

use App\Core\Tenancy\CurrentAgencyRole;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * PL-1.3 tracker roles (plan section 3.3, rulings T3 and Q8). The role is read from the agency of the CURRENT
 * request (see {@see CurrentAgencyRole}). Every ability is a gate (R1 to R6).
 *
 *   view            (R1) admin, manager, staff
 *   update          (R4) admin, manager, staff: status, owner, start and due dates
 *   add             (R2) admin, manager
 *   remove          (R3) admin, manager (a strategy step is refused to everyone, in the service)
 *   rename          (R5) admin, manager: rename a step, move it to another stream
 *   manageStreams   (R6) admin, manager: remove a stream, add a stream back
 */
final class WaveStepPolicy
{
    use HandlesAuthorization;

    public function view(User $user): bool
    {
        return CurrentAgencyRole::for($user) !== null;
    }

    public function update(User $user): bool
    {
        return CurrentAgencyRole::for($user) !== null;
    }

    public function add(User $user): bool
    {
        return CurrentAgencyRole::canManage($user);
    }

    public function remove(User $user): bool
    {
        return CurrentAgencyRole::canManage($user);
    }

    public function rename(User $user): bool
    {
        return CurrentAgencyRole::canManage($user);
    }

    public function manageStreams(User $user): bool
    {
        return CurrentAgencyRole::canManage($user);
    }
}
