<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Policies;

use App\Core\Tenancy\CurrentAgencyRole;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * PL-2.3 concept roles (plan section 3.3, ruling C9). The role is read from the agency of the CURRENT request.
 *
 *   view, create, update, delete, duplicate, manageReferences   admin, manager, staff
 *   recordDecision                                              admin, manager only
 *   deleteDecided                                               admin, manager only (a concept the client decided on; decided in the service, under the lock)
 *
 * Registered for the concept, image, link and decision models.
 */
final class WaveConceptPolicy
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

    public function duplicate(User $user): bool
    {
        return CurrentAgencyRole::for($user) !== null;
    }

    public function manageReferences(User $user): bool
    {
        return CurrentAgencyRole::for($user) !== null;
    }

    public function recordDecision(User $user): bool
    {
        return CurrentAgencyRole::canManage($user);
    }

    public function deleteDecided(User $user): bool
    {
        return CurrentAgencyRole::canManage($user);
    }
}
