<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Policies;

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Agencies\Models\AgencyMembership;
use App\Modules\Brands\Policies\BrandPolicy;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Authorises reads of an agency's creator roster (Sprint 4 Chunk 5).
 *
 * Mirrors {@see BrandPolicy::viewAny} — any
 * agency member (admin / manager / staff) may view the roster list; there
 * is no write surface this chunk (internal_rating editing + roster
 * management are Sprint 6, per D-c5-3).
 *
 * The roster route already sits behind the `tenancy.agency` middleware,
 * which returns a 404 invisibility response for non-members before this
 * gate is reached. The `viewAny` check is therefore belt-and-suspenders
 * that also documents the membership intent (matching the house pattern
 * established by BrandController::index).
 */
final class AgencyCreatorRelationPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->membership($user) !== null;
    }

    private function membership(User $user): ?AgencyMembership
    {
        return AgencyMembership::withoutGlobalScope(BelongsToAgencyScope::class)
            ->where('user_id', $user->id)
            ->whereNotNull('accepted_at')
            ->whereNull('deleted_at')
            ->first();
    }
}
