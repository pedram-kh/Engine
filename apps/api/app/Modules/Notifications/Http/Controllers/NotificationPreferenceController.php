<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Http\Requests\UpdateNotificationPreferencesRequest;
use App\Modules\Notifications\Http\Resources\NotificationPreferenceResource;
use App\Modules\Notifications\Models\NotificationPreference;
use App\Modules\Notifications\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The per-user notification-preference surface (S11.0 Chunk 3b). The product's
 * first user self-WRITE endpoint.
 *
 * Owner-scope is structural (D-7): every action resolves the owner from
 * `$request->user()` — there is NO `{user}` path segment, no policy, no agency
 * id. Preferences are user-global (no BelongsToAgency), so a caller can only
 * ever read or write their OWN rows; there is nothing to enumerate.
 *
 * Storage is SPARSE (D-1): the table holds only divergences from the channel
 * default. The read therefore ships BOTH the sparse rows AND a `defaults` block
 * so the SPA composes full toggle state (`row.is_enabled ?? defaults[channel]`)
 * without hardcoding the preserve-current contract.
 */
final class NotificationPreferenceController
{
    public function __construct(private readonly NotificationService $service) {}

    /**
     * GET /me/notification-preferences — the caller's sparse rows + defaults.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->state($this->user($request), $request);
    }

    /**
     * PATCH /me/notification-preferences — sparse upsert/delete per row.
     *
     * Each row diverging from its channel default materializes a row; each row
     * back at the default deletes any stored row (D-1). Returns the recomputed
     * state in the same shape as {@see self::index()}.
     */
    public function update(UpdateNotificationPreferencesRequest $request): JsonResponse
    {
        $user = $this->user($request);

        foreach ($request->preferences() as $preference) {
            $this->service->setPreference(
                $user,
                $preference['type'],
                $preference['channel'],
                $preference['is_enabled'],
            );
        }

        return $this->state($user, $request);
    }

    private function state(User $user, Request $request): JsonResponse
    {
        $rows = NotificationPreference::query()
            ->where('user_id', $user->getKey())
            ->get();

        return response()->json([
            'data' => [
                'type' => 'notification_preferences',
                'attributes' => [
                    'preferences' => NotificationPreferenceResource::collection($rows)->resolve($request),
                    'defaults' => $this->defaults(),
                ],
            ],
        ]);
    }

    /**
     * The server-authoritative channel defaults (the Ch1 preserve-current
     * contract), keyed by channel value — the FE composes against these.
     *
     * @return array<string, bool>
     */
    private function defaults(): array
    {
        $defaults = [];

        foreach (NotificationChannel::cases() as $channel) {
            $defaults[$channel->value] = $channel->defaultEnabled();
        }

        return $defaults;
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
