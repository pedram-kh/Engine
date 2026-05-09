<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/v1/me            (web guard, main SPA)
 * GET /api/v1/admin/me      (web_admin guard, admin SPA — gated by EnsureMfaForAdmins)
 *
 * Returns the {@see UserResource} for the currently authenticated user.
 * The endpoint exists so the SPA can resolve "who am I?" on cold load
 * (after a refresh or a fresh tab) without paying the cost of a fresh
 * login round-trip; the auth store relies on it as the single source of
 * truth for `isAuthenticated` and the user payload (chunk 6 priority #2).
 *
 * The route is intentionally side-effect free:
 *   - No `last_login_*` stamping (that belongs to the login path).
 *   - No event emission, no audit row.
 *   - No mutation of the session.
 *
 * Authorization layering on the route group:
 *   - `auth:web` (or `auth:web_admin` for the admin variant) returns the
 *     framework's standard 401 envelope when unauthenticated, so this
 *     controller can rely on `$request->user()` being non-null.
 *   - The admin variant ALSO mounts `EnsureMfaForAdmins`, mirroring
 *     every other admin route per chunk 5 priority #7 — an admin who
 *     has not enrolled 2FA receives 403 `auth.mfa.enrollment_required`
 *     and the SPA routes them to `/auth/2fa/enable`.
 *   - `tenancy.set` runs after auth on both variants. For agency_user
 *     it populates the {@see TenancyContext} from the primary
 *     membership; for creators and platform admins it is a documented
 *     no-op (see docs/security/tenancy.md).
 */
final class MeController
{
    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return UserResource::make($user)->response();
    }
}
