<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuthService;
use App\Modules\Identity\Support\LiveAppSession;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Admin view of creator-app usage (AH-102b, Half B).
 *
 *   GET /api/v1/admin/creator-app/users
 *   GET /api/v1/admin/creator-app/users/{creator}/sign-ins
 *
 * DERIVED, NO NEW WRITES. Everything here is read from rows that already
 * exist: the creator-app personal access tokens (`creator-app:{ios|android}`),
 * the `auth.login.succeeded` audit rows the token door already writes
 * (`metadata.guard = 'creator_app'`), and the push-token rows.
 *
 * "Is this sign-in live?" is NEVER re-implemented here: every liveness
 * question goes through {@see LiveAppSession::constrain()}, the same predicate
 * that decides push-delivery eligibility, so the two can never drift apart.
 *
 * Per creator, one of three app states:
 *  - Active     ≥ 1 live creator-app sign-in;
 *  - Signed out no live sign-in, but an app login was audited at some point
 *               (logout/reset/lockout DELETE the token, so the audit trail is
 *               the only memory of a sign-out);
 *  - Never      neither.
 *
 * Cross-agency BY DESIGN — platform_admin bounded bypass
 * (docs/security/tenancy.md § 4). Responses are explicit allow-lists: no
 * token, token hash, PAT id, FCM token, IP or user-agent can appear.
 */
final class AdminCreatorAppController
{
    private const SIGN_IN_CAP = 50;

    private const PLATFORMS = ['ios', 'android'];

    public function index(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin($request);

        $perPage = max(1, min((int) $request->integer('per_page', 25), 100));

        $live = LiveAppSession::constrain(
            DB::table('personal_access_tokens')
                ->join('users', 'users.id', '=', 'personal_access_tokens.tokenable_id')
                ->where('personal_access_tokens.tokenable_type', User::class)
                ->where('personal_access_tokens.name', 'like', AuthService::TOKEN_NAME_PREFIX.'%')
                ->selectRaw(
                    'personal_access_tokens.tokenable_id as user_id, '
                    .'count(*) as live_sign_ins, '
                    .'max(personal_access_tokens.last_used_at) as last_seen_at, '
                    .'max(case when personal_access_tokens.name = ? then 1 else 0 end) as has_ios, '
                    .'max(case when personal_access_tokens.name = ? then 1 else 0 end) as has_android',
                    [AuthService::TOKEN_NAME_PREFIX.'ios', AuthService::TOKEN_NAME_PREFIX.'android'],
                )
                ->groupBy('personal_access_tokens.tokenable_id'),
        );

        // Only APP logins (guard = creator_app): a web sign-in must never turn
        // a creator who never opened the app into "Signed out".
        $audit = DB::table('audit_logs')
            ->where('action', AuditAction::AuthLoginSucceeded->value)
            ->where('actor_type', 'user')
            ->where('metadata->guard', AuthService::TOKEN_GUARD)
            ->selectRaw('actor_id as user_id, max(created_at) as last_app_sign_in_at')
            ->groupBy('actor_id');

        $query = Creator::query()
            ->join('users', 'users.id', '=', 'creators.user_id')
            ->whereNull('users.deleted_at')
            ->leftJoinSub($live, 'live', 'live.user_id', '=', 'creators.user_id')
            ->leftJoinSub($audit, 'audit', 'audit.user_id', '=', 'creators.user_id')
            ->select([
                'creators.id',
                'creators.ulid',
                'creators.user_id',
                'creators.display_name',
                'creators.erased_at',
                'users.email',
                'users.deletion_requested_at',
                'live.live_sign_ins',
                'live.last_seen_at',
                'live.has_ios',
                'live.has_android',
                'audit.last_app_sign_in_at',
            ]);

        $this->applyAppStatusFilter($query, $request->query('app_status'));
        $this->applyPlatformFilter($query, $request->query('platform'));
        $this->applyPushFilter($query, $request->query('push'));
        $this->applySearch($query, $request->query('search'));

        // Last seen desc, then last app sign-in desc, then id desc — NULLS LAST,
        // spelled with a CASE so SQLite (tests) and Postgres order identically.
        $query
            ->orderByRaw('case when live.last_seen_at is null then 1 else 0 end')
            ->orderByDesc('live.last_seen_at')
            ->orderByRaw('case when audit.last_app_sign_in_at is null then 1 else 0 end')
            ->orderByDesc('audit.last_app_sign_in_at')
            ->orderByDesc('creators.id');

        $paginator = $query->paginate($perPage)->withQueryString();

        /** @var list<Creator> $creators */
        $creators = array_values($paginator->items());
        $push = $this->pushSummaryFor(array_map(static fn (Creator $c): int => $c->user_id, $creators));

        $data = array_map(function (Creator $creator) use ($push): array {
            $summary = $push[$creator->user_id] ?? null;
            $liveSignIns = (int) $creator->getAttribute('live_sign_ins');
            $lastSignIn = $creator->getAttribute('last_app_sign_in_at');
            $lastSeen = $creator->getAttribute('last_seen_at');

            $platforms = [];
            if ((int) $creator->getAttribute('has_ios') === 1) {
                $platforms[] = 'ios';
            }
            if ((int) $creator->getAttribute('has_android') === 1) {
                $platforms[] = 'android';
            }

            return [
                'id' => $creator->ulid,
                'type' => 'creator_app_users',
                'attributes' => [
                    'creator' => [
                        'id' => $creator->ulid,
                        'display_name' => $creator->display_name,
                        'email' => $creator->getAttribute('email'),
                        'is_erased' => $creator->isErased(),
                        // AH-104c C2-4: admin only, never an agency (see AdminDeletionFlags).
                        'deletion_pending' => ! $creator->isErased() && $creator->getAttribute('deletion_requested_at') !== null,
                    ],
                    'app_status' => $liveSignIns > 0 ? 'active' : ($lastSignIn !== null ? 'signed_out' : 'never'),
                    'live_sign_ins' => $liveSignIns,
                    'platforms' => $platforms,
                    'last_seen_at' => self::iso($lastSeen),
                    'last_app_sign_in_at' => self::iso($lastSignIn),
                    'app_version' => $summary['app_version'] ?? null,
                    'push_state' => $summary['state'] ?? 'none',
                    'push_invalidation_reason' => $summary['reason'] ?? null,
                    'last_push_registered_at' => self::iso($summary['registered_at'] ?? null),
                ],
            ];
        }, $creators);

        return response()->json([
            'data' => $data,
            'meta' => [
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * The creator's creator-app sign-ins, newest first, capped. A "sign-in" is
     * a creator-app personal access token. Logout / password reset / lockout
     * DELETE the token, so a sign-in that was ended on purpose has no row here
     * (its trace is the audit trail the list view reads) — which is why the
     * only states a row can have are `live` and `expired`.
     *
     * No row id is returned: nothing in this response can reference a token.
     */
    public function signIns(Request $request, string $creator): JsonResponse
    {
        $this->authorizePlatformAdmin($request);

        // Resolved AFTER the admin check (not by implicit route binding), so a
        // non-admin can't probe which creator ULIDs exist via 404-vs-403.
        $row = Creator::query()->where('ulid', $creator)->first();
        $user = $row !== null ? User::query()->find($row->user_id) : null;
        if ($row === null || $user === null) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $own = fn (): Builder => DB::table('personal_access_tokens')
            ->where('personal_access_tokens.tokenable_type', User::class)
            ->where('personal_access_tokens.tokenable_id', $user->id)
            ->where('personal_access_tokens.name', 'like', AuthService::TOKEN_NAME_PREFIX.'%');

        $total = $own()->count();

        $tokens = $own()
            ->orderByDesc('personal_access_tokens.created_at')
            ->orderByDesc('personal_access_tokens.id')
            ->limit(self::SIGN_IN_CAP)
            ->get([
                'personal_access_tokens.id',
                'personal_access_tokens.name',
                'personal_access_tokens.created_at',
                'personal_access_tokens.last_used_at',
                'personal_access_tokens.expires_at',
            ]);

        $ids = $tokens->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        // `live` is decided by the shared predicate, not re-derived in PHP.
        $liveIds = $ids === [] ? [] : LiveAppSession::constrain(
            DB::table('personal_access_tokens')
                ->join('users', 'users.id', '=', 'personal_access_tokens.tokenable_id')
                ->whereIn('personal_access_tokens.id', $ids),
        )->pluck('personal_access_tokens.id')->map(static fn ($id): int => (int) $id)->all();

        $pushByToken = [];
        if ($ids !== []) {
            foreach (DB::table('user_push_tokens')
                ->whereIn('personal_access_token_id', $ids)
                ->get(['personal_access_token_id', 'invalidated_at', 'app_version', 'last_seen_at']) as $push) {
                $pushByToken[(int) $push->personal_access_token_id] = $push;
            }
        }

        $data = [];
        foreach ($tokens as $token) {
            $id = (int) $token->id;
            $isLive = in_array($id, $liveIds, true);
            $push = $pushByToken[$id] ?? null;

            // push_state is about DELIVERABILITY: a registered device under a
            // sign-in that is no longer live can't receive a push, so it reads
            // `none`; its app_version / registered-at stay, as history.
            $pushState = match (true) {
                $push === null => 'none',
                $push->invalidated_at !== null => 'dead',
                $isLive => 'registered',
                default => 'none',
            };

            $suffix = substr((string) $token->name, strlen(AuthService::TOKEN_NAME_PREFIX));

            $data[] = [
                'platform' => in_array($suffix, self::PLATFORMS, true) ? $suffix : null,
                'signed_in_at' => self::iso($token->created_at),
                'last_seen_at' => self::iso($token->last_used_at),
                'state' => $isLive ? 'live' : 'expired',
                'expires_at' => self::iso($token->expires_at),
                'app_version' => $push?->app_version,
                'push_state' => $pushState,
                'push_registered_at' => self::iso($push?->last_seen_at),
            ];
        }

        return response()->json([
            'data' => $data,
            'meta' => [
                'total' => $total,
                'limit' => self::SIGN_IN_CAP,
            ],
        ]);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<Creator>  $query
     */
    private function applyAppStatusFilter($query, mixed $raw): void
    {
        if (! is_string($raw) || $raw === '') {
            return;
        }

        match ($raw) {
            'active' => $query->whereNotNull('live.user_id'),
            'signed_out' => $query->whereNull('live.user_id')->whereNotNull('audit.user_id'),
            'never' => $query->whereNull('live.user_id')->whereNull('audit.user_id'),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<Creator>  $query
     */
    private function applyPlatformFilter($query, mixed $raw): void
    {
        if (! is_string($raw) || $raw === '') {
            return;
        }

        match ($raw) {
            'ios' => $query->where('live.has_ios', 1),
            'android' => $query->where('live.has_android', 1),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * registered = a live-sign-in device the provider hasn't invalidated;
     * dead       = devices exist on live sign-ins but every one is invalidated;
     * none       = no push-token row on any live sign-in.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Creator>  $query
     */
    private function applyPushFilter($query, mixed $raw): void
    {
        if (! is_string($raw) || $raw === '') {
            return;
        }

        match ($raw) {
            'registered' => $query->whereExists($this->livePushRows(registeredOnly: true)),
            'dead' => $query
                ->whereExists($this->livePushRows(registeredOnly: false))
                ->whereNotExists($this->livePushRows(registeredOnly: true)),
            'none' => $query->whereNotExists($this->livePushRows(registeredOnly: false)),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<Creator>  $query
     */
    private function applySearch($query, mixed $raw): void
    {
        if (! is_string($raw) || trim($raw) === '') {
            return;
        }

        $like = '%'.mb_strtolower(trim($raw)).'%';
        $query->where(function ($inner) use ($like): void {
            $inner->whereRaw('LOWER(users.email) LIKE ?', [$like])
                ->orWhereRaw('LOWER(creators.display_name) LIKE ?', [$like]);
        });
    }

    /**
     * Push-token rows that sit on a LIVE creator-app sign-in, correlated to the
     * outer `creators.user_id`.
     */
    private function livePushRows(bool $registeredOnly): Builder
    {
        $rows = LiveAppSession::constrain(
            DB::table('user_push_tokens')
                ->join('personal_access_tokens', 'personal_access_tokens.id', '=', 'user_push_tokens.personal_access_token_id')
                ->join('users', 'users.id', '=', 'user_push_tokens.user_id')
                ->where('personal_access_tokens.name', 'like', AuthService::TOKEN_NAME_PREFIX.'%')
                ->whereColumn('user_push_tokens.user_id', 'creators.user_id'),
        );

        if ($registeredOnly) {
            $rows->whereNull('user_push_tokens.invalidated_at');
        }

        return $rows->select(DB::raw('1'));
    }

    /**
     * One batched query for the whole page: per user, the push state over rows
     * that sit on live sign-ins, the most recently seen row's app version, and
     * for a `dead` user the latest invalidation reason.
     *
     * @param  list<int>  $userIds
     * @return array<int, array{state: string, app_version: string|null, reason: string|null, registered_at: string|null}>
     */
    private function pushSummaryFor(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $rows = LiveAppSession::constrain(
            DB::table('user_push_tokens')
                ->join('personal_access_tokens', 'personal_access_tokens.id', '=', 'user_push_tokens.personal_access_token_id')
                ->join('users', 'users.id', '=', 'user_push_tokens.user_id')
                ->where('personal_access_tokens.name', 'like', AuthService::TOKEN_NAME_PREFIX.'%')
                ->whereIn('user_push_tokens.user_id', $userIds),
        )->get([
            'user_push_tokens.user_id',
            'user_push_tokens.app_version',
            'user_push_tokens.last_seen_at',
            'user_push_tokens.invalidated_at',
            'user_push_tokens.invalidation_reason',
        ]);

        $byUser = [];
        foreach ($rows as $row) {
            $byUser[(int) $row->user_id][] = $row;
        }

        $out = [];
        foreach ($byUser as $userId => $userRows) {
            usort($userRows, static fn (object $a, object $b): int => self::ts($b->last_seen_at) <=> self::ts($a->last_seen_at));

            $registered = array_filter($userRows, static fn (object $r): bool => $r->invalidated_at === null);

            $reason = null;
            if ($registered === []) {
                $invalidated = $userRows;
                usort($invalidated, static fn (object $a, object $b): int => self::ts($b->invalidated_at) <=> self::ts($a->invalidated_at));
                $reason = $invalidated[0]->invalidation_reason;
            }

            $out[$userId] = [
                'state' => $registered !== [] ? 'registered' : 'dead',
                'app_version' => $userRows[0]->app_version,
                'reason' => $reason,
                'registered_at' => $userRows[0]->last_seen_at,
            ];
        }

        return $out;
    }

    private static function ts(mixed $value): int
    {
        return $value === null ? 0 : Carbon::parse((string) $value)->getTimestamp();
    }

    private static function iso(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : Carbon::parse((string) $value)->toIso8601String();
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
