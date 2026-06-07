<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Http\Resources\NotificationResource;
use App\Modules\Notifications\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Admin operational-alerts consumer (Sprint 13, D-12) — the non-payment
 * admin notification surface.
 *
 *   GET /api/v1/admin/alerts
 *
 * The notification subsystem (S11.0) was built for a drop-in admin
 * consumer: a platform_admin is a User, so the same per-recipient feed
 * mechanism serves them. This endpoint is that consumer, scoped to the
 * authenticated admin's own rows (`recipient_user_id = admin`) — the same
 * user-level-above-tenancy isolation as `/me/notifications`.
 *
 * Two deliberate boundaries this sprint:
 *
 *   - PAYMENT-EVENT alerts are HELD BACK (D-13 coming-soon). The
 *     `assignment.payment_funded` / `assignment.payment_released` types
 *     exist (so the consumer is genuinely drop-in), but their emit sites
 *     and the payment admin UI are S10. They are filtered out of the feed
 *     and surfaced as a discrete swappable `meta.payment_alerts` block the
 *     SPA renders as "coming soon" — not silently dropped.
 *
 *   - The feed ships EMPTY-by-default (a shell): the admin operational
 *     emit sites land alongside their features. The surface, the query,
 *     and the envelope all exist now so those emits light up a finished
 *     page rather than building one.
 *
 * Cross-agency / tenant-less BY DESIGN — platform_admin-gated (the bounded
 * bypass) + EnsureMfaForAdmins, like every admin surface. Read-only.
 */
final class AdminAlertsController
{
    public const int DEFAULT_PER_PAGE = 25;

    public const int MAX_PER_PAGE = 100;

    public function index(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin($request);

        $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ]);

        $user = $request->user();
        assert($user instanceof User);

        $perPage = (int) $request->input('per_page', self::DEFAULT_PER_PAGE);

        $paymentValues = array_map(
            static fn (NotificationType $type): string => $type->value,
            NotificationType::paymentAlerts(),
        );

        $paginator = Notification::query()
            ->where('recipient_user_id', $user->getKey())
            ->whereNotIn('type', $paymentValues)
            ->with(['actor', 'subject'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return response()->json([
            'data' => NotificationResource::collection($paginator->items())->resolve($request),
            'meta' => [
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
                // The deferred payment-event consumer (D-13). The SPA renders
                // this discrete block as a coming-soon card; S10 flips
                // `coming_soon` false and lights the held-back types.
                'payment_alerts' => [
                    'coming_soon' => true,
                    'types' => $paymentValues,
                ],
            ],
        ]);
    }

    private function authorizePlatformAdmin(Request $request): void
    {
        $user = $request->user();
        if ($user === null) {
            abort(Response::HTTP_UNAUTHORIZED);
        }
        if ($user->type !== UserType::PlatformAdmin) {
            abort(Response::HTTP_FORBIDDEN);
        }
    }
}
