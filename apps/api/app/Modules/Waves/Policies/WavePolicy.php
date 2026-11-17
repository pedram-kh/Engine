<?php

declare(strict_types=1);

namespace App\Modules\Waves\Policies;

use App\Core\Tenancy\CurrentAgencyRole;
use App\Modules\Identity\Models\User;
use App\Modules\Waves\Models\Wave;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * PL-1.1 wave roles (ruling Q4): admin and manager create and edit; staff view only. The role is read from the
 * agency of the current request, not the user's first membership (see {@see CurrentAgencyRole}).
 */
final class WavePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return CurrentAgencyRole::for($user) !== null;
    }

    public function view(User $user, Wave $wave): bool
    {
        return CurrentAgencyRole::for($user) !== null;
    }

    public function create(User $user): bool
    {
        return CurrentAgencyRole::canManage($user);
    }

    public function update(User $user, Wave $wave): bool
    {
        return CurrentAgencyRole::canManage($user);
    }
}
