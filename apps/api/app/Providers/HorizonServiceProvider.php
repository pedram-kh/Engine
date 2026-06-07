<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

/**
 * Horizon embed authorization (Sprint 13, D-8).
 *
 * Horizon is embedded (not custom-built — custom ops views are tech-debt)
 * and gated behind the SAME bound as every admin surface: a platform_admin,
 * authenticated on the `web_admin` guard (the two-cookie model), with MFA
 * enrolled. The authorization is gate-based by Horizon's design — its
 * `Authenticate` middleware aborts 403 when `Horizon::check()` returns
 * false — so we fold all three obligations into the authoritative gate
 * rather than the route-middleware MFA primitive (which resolves the
 * default `web` guard, not `web_admin`).
 *
 * The `web_admin` session reaches `/horizon` because UseAdminSessionCookie
 * swaps the cookie name on the `horizon` path prefix (its `shouldApply`),
 * so StartSession in the `web` middleware group reads the admin cookie.
 *
 * Divergence (surfaced in the review): the default Horizon stub gates by an
 * email allow-list and opens access in `local`. We gate by user TYPE on the
 * admin guard and DROP the local bypass so the bound is enforced uniformly
 * (an authenticated local admin still passes — the bypass only ever opened
 * Horizon to unauthenticated local requests, which we do not want even in
 * dev). Cross-tenant allowlist entry added to docs/security/tenancy.md § 4.
 */
class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();
    }

    protected function authorization(): void
    {
        $this->gate();

        Horizon::auth(function (Request $request): bool {
            $user = $request->user('web_admin');

            return $user instanceof User && Gate::forUser($user)->check('viewHorizon');
        });
    }

    protected function gate(): void
    {
        Gate::define('viewHorizon', static function (?User $user = null): bool {
            return $user instanceof User
                && $user->type === UserType::PlatformAdmin
                && $user->hasTwoFactorEnabled();
        });
    }
}
