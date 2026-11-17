<?php

declare(strict_types=1);

namespace App\Modules\Brands\Policies;

use App\Core\Tenancy\CurrentAgencyRole;
use App\Modules\Brands\Models\BrandFile;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * PL-1.1 client-file roles (ruling Q4): admin and manager upload and delete; staff view and download only. The
 * role is read from the agency of the current request (see {@see CurrentAgencyRole}).
 */
final class BrandFilePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return CurrentAgencyRole::for($user) !== null;
    }

    public function view(User $user, BrandFile $file): bool
    {
        return CurrentAgencyRole::for($user) !== null;
    }

    public function create(User $user): bool
    {
        return CurrentAgencyRole::canManage($user);
    }

    public function delete(User $user, BrandFile $file): bool
    {
        return CurrentAgencyRole::canManage($user);
    }
}
