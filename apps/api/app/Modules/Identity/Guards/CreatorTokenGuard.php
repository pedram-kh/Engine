<?php

declare(strict_types=1);

namespace App\Modules\Identity\Guards;

use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use Illuminate\Http\Request;
use Laravel\Sanctum\Events\TokenAuthenticated;
use Laravel\Sanctum\Guard;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

/**
 * Bearer-token-ONLY guard for the creator app (AH-099, D2).
 *
 * Mounted beside the session guard as `auth:web,creator_app` on the
 * creator-reachable routes. Laravel's Authenticate middleware tries the
 * listed guards in order and stops at the first that authenticates, so:
 *
 *   - a cookie request is authenticated by the `web` guard exactly as it
 *     always was (same default guard, no transient token attached) — the
 *     web path is untouched, not merely equivalent;
 *   - a bearer request (which carries no session) falls through to this
 *     guard.
 *
 * WHY NOT `auth:sanctum` / Sanctum's own guard. {@see Guard::__invoke()}
 * first loops `config('sanctum.guard')` — `['web', 'web_admin']` — and
 * returns whichever session user it finds. A swap to `auth:sanctum` would
 * therefore let a platform-admin (`web_admin`) session authenticate on
 * creator endpoints (on `GET /me` it would return the admin's own data).
 * This guard overrides `__invoke` to run ONLY the token half, so
 * `web_admin` — and every session guard — is structurally absent from the
 * chain. `config('sanctum.guard')` is deliberately left untouched.
 *
 * The token half itself is Sanctum's own (`findToken`, expiry via
 * `isValidAccessToken`, provider check, `last_used_at` tracking) — only the
 * session loop is removed and the use-time checks below are added.
 *
 * USE-TIME CHECKS (AH-099 Q2). The token must belong to a Creator who is
 * not suspended, on every request — not just at mint. This is defence in
 * depth: revocation at the suspend site (D3) is the primary control, and
 * this makes any FUTURE suspend site that forgets to revoke tokens fail
 * closed anyway. It is a deliberate asymmetry — the token path is stricter
 * than the session path, which still checks suspension at login only
 * (tech-debt: "sessions outlive suspension").
 */
final class CreatorTokenGuard extends Guard
{
    public function __invoke(Request $request): ?User
    {
        $token = $this->getTokenFromRequest($request);

        if ($token === null || $token === '') {
            return null;
        }

        $model = Sanctum::$personalAccessTokenModel;
        $accessToken = $model::findToken($token);

        if (! $accessToken instanceof PersonalAccessToken || ! $this->isValidAccessToken($accessToken)) {
            return null;
        }

        $tokenable = $accessToken->tokenable;

        if (! $tokenable instanceof User) {
            return null;
        }

        // A creator scheduled for deletion has no way in (AH-104b-del): their tokens are
        // revoked at the request, and one that somehow survives is refused here.
        if ($tokenable->type !== UserType::Creator || $tokenable->isSuspended() || $tokenable->isDeletionPending()) {
            return null;
        }

        $user = $tokenable->withAccessToken($accessToken);

        event(new TokenAuthenticated($accessToken));

        if ($this->trackLastUsedAt) {
            $this->updateLastUsedAt($accessToken);
        }

        return $user instanceof User ? $user : null;
    }
}
