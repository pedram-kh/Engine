<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Http\Requests\DeletePushTokenRequest;
use App\Modules\Notifications\Http\Requests\RegisterPushTokenRequest;
use App\Modules\Notifications\Models\UserPushToken;
use App\Modules\Notifications\Push\PushTokenRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The device push-token surface of the creator app (AH-102).
 *
 * BEARER-ONLY (`auth:creator_app` alone, unlike the `auth:web,creator_app`
 * creator surfaces): a push token is bound to the personal access token it
 * registered under — that binding is what makes logout, password reset and
 * suspension revoke the device automatically. A cookie session has no
 * bearer to bind to, so it is refused (401) rather than half-supported.
 *
 * Owner-scope is structural: the owner is the authenticated user, never a
 * request field; DELETE is scoped to the caller's own rows, and answers 204
 * whether or not a row matched (no oracle for other users' tokens).
 *
 * Registration is deliberately NOT gated on `push_notifications_enabled`
 * (Q12): the flag gates SENDING only, so tokens accumulate and the flag can be
 * armed with devices already registered.
 */
final class PushTokenController
{
    public function __construct(private readonly PushTokenRegistry $registry) {}

    /**
     * POST /me/push-tokens — register or refresh this device's token.
     */
    public function store(RegisterPushTokenRequest $request): Response
    {
        $user = $this->user($request);
        $bearer = $user->currentAccessToken();

        // The guard only ever attaches a real PersonalAccessToken; anything else
        // (a transient cookie token) has nothing to bind a device to.
        abort_unless($bearer instanceof PersonalAccessToken, 401);

        $this->registry->register(
            $user,
            $bearer,
            $request->platform(),
            $request->deviceToken(),
            $request->appVersion(),
        );

        return response()->noContent();
    }

    /**
     * DELETE /me/push-tokens — forget one of the caller's OWN device tokens.
     */
    public function destroy(DeletePushTokenRequest $request): Response
    {
        UserPushToken::query()
            ->where('user_id', $this->user($request)->getKey())
            ->where('token', $request->deviceToken())
            ->delete();

        return response()->noContent();
    }

    private function user(Request $request): User
    {
        $user = $request->user('creator_app');

        abort_unless($user instanceof User, 401);

        return $user;
    }
}
