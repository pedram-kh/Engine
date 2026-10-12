<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\PushDelivery;
use App\Modules\Notifications\Push\PushOutcome;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Admin view of the push delivery attempt log (AH-102b, Half A).
 *
 *   GET /api/v1/admin/push-deliveries
 *   GET /api/v1/admin/push-deliveries/summary?window=24h|7d|30d
 *
 * Read-only, cross-agency BY DESIGN (platform_admin bounded bypass,
 * docs/security/tenancy.md § 4); `push_deliveries` is user-global and carries
 * no `agency_id`. Cursor-paginated on the monotonic `id` (≈ time, stable
 * cursor) like {@see AdminAuditLogController}.
 *
 * Filter discipline: an unknown or unparseable filter value yields an EMPTY
 * page (`1 = 0`) — never a 500 and never "all rows".
 *
 * Responses are explicit allow-lists. Nothing here can return an FCM token, a
 * message title/body/data, an IP or a user-agent: none of them is stored on
 * `push_deliveries`, and the creator block is the three identity fields only.
 */
final class AdminPushDeliveryController
{
    /** @var array<string, string> window → the Carbon `sub*` interval */
    private const WINDOWS = ['24h' => '24 hours', '7d' => '7 days', '30d' => '30 days'];

    private const DEFAULT_WINDOW = '7d';

    /** Preset: terminal non-delivered attempts (a retried push that finally gave up counts once). */
    private const FAILURES = 'failures';

    public function index(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin($request);

        $perPage = max(1, min((int) $request->integer('per_page', 50), 100));

        $query = PushDelivery::query()->orderByDesc('id');

        $this->applyOutcomeFilter($query, $request->query('outcome'));
        $this->applyTypeFilter($query, $request->query('notification_type'));
        $this->applyCreatorFilter($query, $request->query('creator'));
        $this->applyDateFilter($query, $request->query('date_from'), '>=', startOfDay: true);
        $this->applyDateFilter($query, $request->query('date_to'), '<=', startOfDay: false);

        $paginator = $query->cursorPaginate($perPage)->withQueryString();

        /** @var list<PushDelivery> $rows */
        $rows = array_values($paginator->items());

        $creators = $this->creatorsFor($rows);
        $inApp = $this->inAppStateFor($rows);

        return response()->json([
            'data' => array_map(
                static fn (PushDelivery $row): array => self::serialize(
                    $row,
                    $creators[$row->user_id] ?? null,
                    $row->notification_ulid !== null ? ($inApp[$row->notification_ulid] ?? 'none') : 'none',
                ),
                $rows,
            ),
            'meta' => [
                'per_page' => $paginator->perPage(),
                'next_cursor' => $paginator->nextCursor()?->encode(),
                'prev_cursor' => $paginator->previousCursor()?->encode(),
                'has_more' => $paginator->hasMorePages(),
            ],
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin($request);

        $validated = $request->validate([
            'window' => ['nullable', 'string', Rule::in(array_keys(self::WINDOWS))],
        ]);
        $window = $validated['window'] ?? self::DEFAULT_WINDOW;
        $since = Carbon::now()->modify('-'.self::WINDOWS[$window]);

        // TERMINAL attempts only: every non-retryable outcome, plus a retryable
        // one only when it was the final attempt. A push that was retried twice
        // and then delivered is ONE delivered, not two retryables and a delivery.
        $grouped = PushDelivery::query()
            ->where('created_at', '>=', $since)
            ->where(function (Builder $q): void {
                $q->where('outcome', '!=', PushOutcome::Retryable->value)
                    ->orWhere('final', true);
            })
            ->selectRaw('outcome, count(*) as total')
            ->groupBy('outcome')
            ->pluck('total', 'outcome');

        $count = static fn (PushOutcome $outcome): int => (int) ($grouped[$outcome->value] ?? 0);

        // The footer (Q6): bounded by retention; `min(created_at)` rides the
        // created_at index. It tells an admin the log's real depth — a window
        // longer than the table's history is not "zero failures".
        $oldest = PushDelivery::query()->orderBy('created_at')->value('created_at');

        return response()->json([
            'window' => $window,
            'since' => $since->toIso8601String(),
            'counts' => [
                'delivered' => $count(PushOutcome::Delivered),
                'token_invalid' => $count(PushOutcome::TokenInvalid),
                'retryable_final' => $count(PushOutcome::Retryable),
                'config_error' => $count(PushOutcome::ConfigError),
                'rejected' => $count(PushOutcome::Rejected),
            ],
            'table' => [
                'total_rows' => PushDelivery::query()->count(),
                'oldest_at' => $oldest instanceof Carbon ? $oldest->toIso8601String() : null,
            ],
        ]);
    }

    /**
     * @param  Builder<PushDelivery>  $query
     */
    private function applyOutcomeFilter(Builder $query, mixed $raw): void
    {
        if (! is_string($raw) || $raw === '') {
            return;
        }

        if ($raw === self::FAILURES) {
            $query->where(function (Builder $q): void {
                $q->whereIn('outcome', [
                    PushOutcome::TokenInvalid->value,
                    PushOutcome::ConfigError->value,
                    PushOutcome::Rejected->value,
                ])->orWhere(function (Builder $retried): void {
                    $retried->where('outcome', PushOutcome::Retryable->value)->where('final', true);
                });
            });

            return;
        }

        $outcome = PushOutcome::tryFrom($raw);
        $outcome === null
            ? $query->whereRaw('1 = 0')
            : $query->where('outcome', $outcome->value);
    }

    /**
     * @param  Builder<PushDelivery>  $query
     */
    private function applyTypeFilter(Builder $query, mixed $raw): void
    {
        if (! is_string($raw) || $raw === '') {
            return;
        }

        $type = NotificationType::tryFrom($raw);
        $type === null
            ? $query->whereRaw('1 = 0')
            : $query->where('notification_type', $type->value);
    }

    /**
     * @param  Builder<PushDelivery>  $query
     */
    private function applyCreatorFilter(Builder $query, mixed $raw): void
    {
        if (! is_string($raw) || $raw === '') {
            return;
        }

        $userId = Creator::withTrashed()->where('ulid', $raw)->value('user_id');
        $userId === null
            ? $query->whereRaw('1 = 0')
            : $query->where('user_id', (int) $userId);
    }

    /**
     * @param  Builder<PushDelivery>  $query
     */
    private function applyDateFilter(Builder $query, mixed $raw, string $operator, bool $startOfDay): void
    {
        if (! is_string($raw) || $raw === '') {
            return;
        }

        try {
            $date = Carbon::parse($raw);
        } catch (\Throwable) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where('created_at', $operator, $startOfDay ? $date->startOfDay() : $date->endOfDay());
    }

    /**
     * Resolve every creator on the page in ONE query (plus one for emails) —
     * `withTrashed` so a deleted account's history still reads.
     *
     * @param  list<PushDelivery>  $rows
     * @return array<int, array{id: string, display_name: string|null, email: string|null}>
     */
    private function creatorsFor(array $rows): array
    {
        $userIds = array_values(array_unique(array_map(static fn (PushDelivery $r): int => $r->user_id, $rows)));
        if ($userIds === []) {
            return [];
        }

        $emails = User::withTrashed()->whereIn('id', $userIds)->pluck('email', 'id');

        $out = [];
        foreach (Creator::withTrashed()->whereIn('user_id', $userIds)->get(['id', 'ulid', 'user_id', 'display_name']) as $creator) {
            $out[$creator->user_id] = [
                'id' => $creator->ulid,
                'display_name' => $creator->display_name,
                'email' => $emails[$creator->user_id] ?? null,
            ];
        }

        return $out;
    }

    /**
     * The in-app leg of the channel strip, for the whole page in ONE query:
     * `read` | `unread`; a row with no `notification_ulid`, or whose
     * notification row is gone, is simply absent here and reads as `none`.
     *
     * @param  list<PushDelivery>  $rows
     * @return array<string, 'read'|'unread'>
     */
    private function inAppStateFor(array $rows): array
    {
        $ulids = array_values(array_unique(array_filter(
            array_map(static fn (PushDelivery $r): ?string => $r->notification_ulid, $rows),
        )));
        if ($ulids === []) {
            return [];
        }

        $out = [];
        foreach (Notification::query()->whereIn('ulid', $ulids)->get(['ulid', 'read_at']) as $notification) {
            $out[$notification->ulid] = $notification->read_at !== null ? 'read' : 'unread';
        }

        return $out;
    }

    /**
     * @param  array{id: string, display_name: string|null, email: string|null}|null  $creator
     * @return array<string, mixed>
     */
    private static function serialize(PushDelivery $row, ?array $creator, string $inApp): array
    {
        return [
            'id' => (string) $row->id,
            'type' => 'push_deliveries',
            'attributes' => [
                'created_at' => $row->created_at->toIso8601String(),
                'platform' => $row->platform->value,
                'notification_type' => $row->notification_type,
                'outcome' => $row->outcome->value,
                'attempt' => $row->attempt,
                'final' => $row->final,
                'code' => $row->code,
                'http_status' => $row->http_status,
                'detail' => $row->detail,
                'creator' => $creator,
                'in_app' => $inApp,
            ],
        ];
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
