<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Http\Resources\NotificationResource;
use App\Modules\Notifications\Models\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The per-user notification feed (S11.0 Chunk 1, D-8).
 *
 * Every action is owner-scoped to `recipient_user_id = auth user` (D-9):
 * notifications are user-level, above tenancy. Both agency users and creators
 * are Users hitting the same endpoints. A notification ULID that is not the
 * caller's own is simply not found (404) — the structural owner-only guard,
 * no cross-user enumeration.
 */
final class NotificationController
{
    public const int DEFAULT_PER_PAGE = 25;

    public const int MAX_PER_PAGE = 100;

    /**
     * GET /me/notifications — the paginated feed, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ]);

        $user = $this->user($request);
        $perPage = (int) $request->input('per_page', self::DEFAULT_PER_PAGE);

        $paginator = Notification::query()
            ->where('recipient_user_id', $user->getKey())
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
                'unread_count' => $this->unreadQuery($user)->count(),
            ],
        ]);
    }

    /**
     * GET /me/notifications/unread-count — the cheap count-only endpoint.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $user = $this->user($request);

        return response()->json([
            'data' => [
                'type' => 'notification_unread_count',
                'attributes' => [
                    'unread_count' => $this->unreadQuery($user)->count(),
                ],
            ],
        ]);
    }

    /**
     * PATCH /me/notifications/{notification}/read — idempotent per §5.6:
     * re-marking an already-read row is a no-op (read_at preserved).
     */
    public function markRead(Request $request, string $notification): JsonResponse
    {
        $user = $this->user($request);

        $model = Notification::query()
            ->where('recipient_user_id', $user->getKey())
            ->where('ulid', $notification)
            ->first();

        if ($model === null) {
            return ErrorResponse::single($request, 404, 'notification.not_found', 'Notification not found.');
        }

        $model->markRead();

        return response()->json([
            'data' => [
                'type' => 'notifications',
                'id' => $model->ulid,
                'attributes' => [
                    'read_at' => $model->read_at?->toIso8601String(),
                ],
            ],
            'meta' => [
                'code' => 'notification.read',
            ],
        ]);
    }

    /**
     * POST /me/notifications/read-all — marks every unread row read. Idempotent:
     * a feed with nothing unread returns marked_count 0 and writes nothing.
     */
    public function readAll(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $marked = $this->unreadQuery($user)->update(['read_at' => now()]);

        return response()->json([
            'data' => [
                'type' => 'notification_read_all',
                'attributes' => [
                    'marked_count' => $marked,
                ],
            ],
            'meta' => [
                'code' => 'notification.read_all',
            ],
        ]);
    }

    /**
     * @return Builder<Notification>
     */
    private function unreadQuery(User $user): Builder
    {
        return Notification::query()
            ->where('recipient_user_id', $user->getKey())
            ->whereNull('read_at');
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
