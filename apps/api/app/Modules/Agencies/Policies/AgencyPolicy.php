<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Policies;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Authorization for the Sprint-13 admin agency-management surface (D-3).
 *
 * Every ability here is the platform-admin bounded bypass: admin agency
 * tooling is cross-agency BY DESIGN (docs/security/tenancy.md § 4), so
 * the gate is purely `user_type === platform_admin`. Agency users — even
 * an agency_admin of the agency in question — get 403 (the
 * AdminCreatorIndexTest precedent): managing agencies platform-wide is an
 * admin-console concern, not an in-agency one.
 *
 * The mandatory-reason + audit obligations for suspend live in the
 * controller + AuditLogger (the agency.suspended verb requiresReason()),
 * not here — the policy answers only "may this actor reach the action".
 */
final class AgencyPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->type === UserType::PlatformAdmin;
    }

    public function view(User $user, Agency $agency): bool
    {
        return $user->type === UserType::PlatformAdmin;
    }

    /**
     * Suspend an agency — blocks every agency user's login. Mandatory
     * reason is enforced downstream (the agency.suspended audit verb).
     */
    public function suspend(User $user, Agency $agency): bool
    {
        return $user->type === UserType::PlatformAdmin;
    }

    /**
     * Reactivate a suspended agency — clears the suspension and restores
     * login. Same gate as suspend.
     */
    public function reactivate(User $user, Agency $agency): bool
    {
        return $user->type === UserType::PlatformAdmin;
    }

    /**
     * List this agency's members from the admin console (AH-087). Same flat
     * gate as the rest of this policy: an agency's own agency_admin reads
     * their roster through the tenant-scoped agency-self endpoint
     * (GET /agencies/{agency}/members), not through the admin console.
     */
    public function viewMembers(User $user, Agency $agency): bool
    {
        return $user->type === UserType::PlatformAdmin;
    }

    /**
     * Act on a member's 2FA state — reset enrollment, grant or remove an
     * exemption (AH-087). The gate is the same flat platform-admin check;
     * the operation-specific limits live in the controller, which is where
     * the target matters rather than the actor: a platform-admin TARGET is
     * never exemptable, and every operation carries a mandatory reason.
     */
    public function manageMemberMfa(User $user, Agency $agency): bool
    {
        return $user->type === UserType::PlatformAdmin;
    }
}
