<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Queue;

use App\Modules\Creators\Models\Creator;
use App\Modules\Erasure\Eligibility\ErasureEligibility;
use App\Modules\Erasure\Enums\ErasureLedgerState;
use App\Modules\Erasure\Models\ErasureLedger;
use App\Modules\Erasure\Subject\SubjectNotErasable;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The erasure queue's read side (AH-104c C2): who is waiting to be erased, in which state, and
 * what stands in the way. Read-only. The write side is `ErasureClaimer` and `ErasureHold`.
 *
 * The state of an account is DERIVED, never stored (plan §3.1), by this precedence:
 *
 *   erased > in_progress (claimed) > held > blocked > ready > pending
 *
 * - **pending**: a request stands and its grace has not ended.
 * - **ready**: the grace has ended, not held, not claimed, and no guard refuses.
 * - **blocked**: due, but a guard refuses (coarse codes only: payment, delivered_work, vendor).
 * - **held**: an admin's legal hold stands (with or without a request).
 * - **in_progress**: claimed for erasure and not finished (the ledger says how far it got, and
 *   {@see ErasureRunStatus} whether it looks stuck). An account whose run stopped at `db_done` is already
 *   erased on its row but still listed here until the ledger mirror is written.
 * - **erased**: read from `erasure_ledger` ONLY. The user row is anonymised, so no name or email
 *   exists to show and nothing here joins to it for one.
 *
 * Blockers are the guards' own verdict (`ErasureEligibility`), asked per account, so the queue
 * cannot disagree with the gate. They cost a handful of queries each, and the queue is tens of
 * rows, so the pending, held and in-progress tabs never ask them (they are plain SQL); only the
 * due accounts are evaluated, and at most {@see self::DUE_SCAN_CAP} of them per request (the
 * response says when the cap was hit). Rows are listed in id order with an opaque cursor.
 */
final class ErasureQueue
{
    public const PENDING = 'pending';

    public const READY = 'ready';

    public const BLOCKED = 'blocked';

    public const HELD = 'held';

    public const IN_PROGRESS = 'in_progress';

    public const ERASED = 'erased';

    /** @var list<string> */
    public const TABS = [self::PENDING, self::READY, self::BLOCKED, self::HELD, self::IN_PROGRESS, self::ERASED];

    /** A due account whose subject cannot be resolved (no creator profile): refused, and shown so. */
    public const NOT_ERASABLE = 'not_erasable';

    /** The most due accounts evaluated for blockers in one request. */
    public const DUE_SCAN_CAP = 200;

    /** @var array{verdicts: array<int, list<string>>, capped: bool}|null the due scan, once per instance */
    private ?array $due = null;

    public function __construct(private readonly ErasureEligibility $eligibility) {}

    /**
     * @return array{pending: int, ready: int, blocked: int, held: int, in_progress: int, erased: int, due_scan_capped: bool}
     */
    public function counts(): array
    {
        $due = $this->classifyDue(null);

        return [
            self::PENDING => $this->base()->where($this->pendingOnly(...))->count(),
            self::READY => count(array_filter($due['verdicts'], static fn (array $blockers): bool => $blockers === [])),
            self::BLOCKED => count(array_filter($due['verdicts'], static fn (array $blockers): bool => $blockers !== [])),
            self::HELD => $this->base()->where($this->heldOnly(...))->count(),
            self::IN_PROGRESS => $this->inProgress()->count(),
            self::ERASED => ErasureLedger::query()->where('state', ErasureLedgerState::Completed->value)->count(),
            'due_scan_capped' => $due['capped'],
        ];
    }

    /**
     * One page of one tab. The cursor is the last id of the previous page (opaque to callers).
     *
     * @return array{rows: list<array<string, mixed>>, next_cursor: ?string}
     */
    public function page(string $tab, ?string $cursor, int $perPage): array
    {
        $after = $this->decode($cursor);

        if ($tab === self::ERASED) {
            return $this->erasedPage($after, $perPage);
        }

        if ($tab === self::READY || $tab === self::BLOCKED) {
            return $this->duePage($tab, $after, $perPage);
        }

        $query = match ($tab) {
            self::PENDING => $this->base()->where($this->pendingOnly(...)),
            self::HELD => $this->base()->where($this->heldOnly(...)),
            self::IN_PROGRESS => $this->inProgress(),
            default => throw new \InvalidArgumentException("Unknown queue tab: {$tab}"),
        };
        $query->orderBy('users.id')->limit($perPage + 1);
        if ($after !== null) {
            $query->where('users.id', '>', $after);
        }

        /** @var list<User> $users */
        $users = $query->get()->all();

        return $this->slice($users, $perPage, fn (array $slice): array => $this->present($slice, null));
    }

    /** The queue row of one account (for the hold, release and status answers), or null when it is not in the queue. */
    public function rowFor(User $user): ?array
    {
        if ($user->type !== UserType::Creator) {
            return null;
        }
        if ($user->isErased()) {
            // An erased account stays on the queue only while its run is unfinished (db_done): it needs a person.
            if (! $this->hasUnfinishedRun($user)) {
                return null;
            }
        } elseif ($user->deletion_requested_at === null && ! $user->isErasureHeld() && ! $user->isErasureClaimed()) {
            return null;
        }

        $blockers = ! $user->isErased() && $this->isDue($user) ? $this->blockersOf($user) : [];

        return $this->present([$user], [$user->id => $blockers])[0];
    }

    private function hasUnfinishedRun(User $user): bool
    {
        return $user->erasure_started_at !== null
            && ErasureLedger::query()->where('user_ulid', $user->ulid)->where('state', ErasureLedgerState::DbDone->value)->exists();
    }

    /**
     * Claimed accounts, plus erased ones whose run stopped at `db_done` (the mirror failed): the
     * second kind must stay visible, because nothing else in the page would show them (P10).
     *
     * @return Builder<User>
     */
    private function inProgress(): Builder
    {
        return User::query()
            ->where('users.type', UserType::Creator->value)
            ->whereNotNull('users.erasure_started_at')
            ->where(function (Builder $q): void {
                $q->whereNull('users.erased_at')->orWhereExists(
                    DB::table('erasure_ledger')
                        ->selectRaw('1')
                        ->whereColumn('erasure_ledger.user_ulid', 'users.ulid')
                        ->where('erasure_ledger.state', ErasureLedgerState::DbDone->value),
                );
            });
    }

    // ── The cheap tabs' predicates (SQL only) ───────────────────────────────

    /** @return Builder<User> */
    private function base(): Builder
    {
        return User::query()
            ->where('users.type', UserType::Creator->value)
            ->whereNull('users.erased_at')
            ->where(function (Builder $q): void {
                $q->whereNotNull('users.deletion_requested_at')
                    ->orWhereNotNull('users.erasure_held_at')
                    ->orWhereNotNull('users.erasure_started_at');
            });
    }

    /** @param  Builder<User>  $q */
    private function heldOnly(Builder $q): void
    {
        $q->whereNull('users.erasure_started_at')->whereNotNull('users.erasure_held_at');
    }

    /**
     * Requested, not held, not claimed.
     *
     * @param  Builder<User>  $q
     */
    private function requestedOnly(Builder $q): void
    {
        $q->whereNull('users.erasure_started_at')
            ->whereNull('users.erasure_held_at')
            ->whereNotNull('users.deletion_requested_at')
            ->whereNotNull('users.deletion_scheduled_for');
    }

    /** @param  Builder<User>  $q */
    private function pendingOnly(Builder $q): void
    {
        $this->requestedOnly($q);
        $q->where('users.deletion_scheduled_for', '>', now());
    }

    /** @param  Builder<User>  $q */
    private function dueOnly(Builder $q): void
    {
        $this->requestedOnly($q);
        $q->where('users.deletion_scheduled_for', '<=', now());
    }

    // ── The due accounts: ready and blocked, split by the guards ────────────

    /**
     * The due accounts after `$after`, each with its blockers, in id order. The scan runs once per
     * instance (the counts and the page of one request share it); the instance is per request.
     *
     * @return array{verdicts: array<int, list<string>>, capped: bool} blockers keyed by user id
     */
    private function classifyDue(?int $after): array
    {
        if ($this->due === null) {
            $users = $this->base()->where($this->dueOnly(...))->orderBy('users.id')->limit(self::DUE_SCAN_CAP + 1)->get()->all();
            $capped = count($users) > self::DUE_SCAN_CAP;

            $verdicts = [];
            foreach (array_slice($users, 0, self::DUE_SCAN_CAP) as $user) {
                $verdicts[$user->id] = $this->blockersOf($user);
            }
            $this->due = ['verdicts' => $verdicts, 'capped' => $capped];
        }

        if ($after === null) {
            return $this->due;
        }

        return [
            'verdicts' => array_filter($this->due['verdicts'], static fn (int $id): bool => $id > $after, ARRAY_FILTER_USE_KEY),
            'capped' => $this->due['capped'],
        ];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, next_cursor: ?string}
     */
    private function duePage(string $tab, ?int $after, int $perPage): array
    {
        $wantBlocked = $tab === self::BLOCKED;
        $due = $this->classifyDue($after);

        $matching = array_keys(array_filter(
            $due['verdicts'],
            static fn (array $blockers): bool => ($blockers !== []) === $wantBlocked,
        ));
        $ids = array_slice($matching, 0, $perPage + 1);

        /** @var list<User> $users */
        $users = $ids === [] ? [] : User::query()->whereIn('users.id', $ids)->orderBy('users.id')->get()->all();

        return $this->slice($users, $perPage, fn (array $slice): array => $this->present($slice, $due['verdicts']));
    }

    /** @return list<string> */
    private function blockersOf(User $user): array
    {
        try {
            return $this->eligibility->blockers($user);
        } catch (SubjectNotErasable) {
            return [self::NOT_ERASABLE];
        }
    }

    private function isDue(User $user): bool
    {
        return $user->deletion_requested_at !== null
            && $user->deletion_scheduled_for !== null
            && ! $user->deletion_scheduled_for->isFuture()
            && ! $user->isErasureHeld()
            && ! $user->isErasureClaimed();
    }

    // ── The erased tab: the ledger only ─────────────────────────────────────

    /**
     * @return array{rows: list<array<string, mixed>>, next_cursor: ?string}
     */
    private function erasedPage(?int $after, int $perPage): array
    {
        $query = ErasureLedger::query()
            ->where('state', ErasureLedgerState::Completed->value)
            ->orderByDesc('id')
            ->limit($perPage + 1);
        if ($after !== null) {
            $query->where('id', '<', $after);
        }

        /** @var list<ErasureLedger> $rows */
        $rows = $query->get()->all();
        $more = count($rows) > $perPage;
        $rows = array_slice($rows, 0, $perPage);

        return [
            'rows' => array_map(static fn (ErasureLedger $row): array => [
                'id' => $row->user_ulid,
                'type' => 'erasure_queue_rows',
                'attributes' => [
                    'state' => self::ERASED,
                    'ledger_state' => $row->state->value,
                    'erased_at' => $row->completed_at?->toIso8601String(),
                ],
            ], $rows),
            'next_cursor' => $more && $rows !== [] ? $this->encode((int) $rows[array_key_last($rows)]->id) : null,
        ];
    }

    // ── Presentation ────────────────────────────────────────────────────────

    /**
     * @param  list<User>  $users
     * @param  array<int, list<string>>|null  $verdicts  blockers by user id; null when the page is not a due tab
     * @return list<array<string, mixed>>
     */
    private function present(array $users, ?array $verdicts): array
    {
        if ($users === []) {
            return [];
        }

        $userIds = array_map(static fn (User $u): int => $u->id, $users);
        $creatorUlids = Creator::query()->whereIn('user_id', $userIds)->pluck('ulid', 'user_id')->all();
        $holderIds = array_values(array_filter(array_map(static fn (User $u): ?int => $u->erasure_held_by_user_id, $users)));
        $holders = $holderIds === [] ? [] : User::query()->withTrashed()->whereIn('id', $holderIds)->pluck('name', 'id')->all();
        $ulids = array_map(static fn (User $u): string => $u->ulid, array_filter($users, static fn (User $u): bool => $u->erasure_started_at !== null));
        $ledger = $ulids === [] ? [] : ErasureLedger::query()->whereIn('user_ulid', $ulids)->orderBy('id')->get()->keyBy('user_ulid')->all();

        $rows = [];
        foreach ($users as $user) {
            $blockers = $verdicts[$user->id] ?? [];
            $state = $this->stateOf($user, $blockers);
            $run = ErasureRunStatus::for($user, $ledger[$user->ulid] ?? null);

            $rows[] = [
                'id' => $user->ulid,
                'type' => 'erasure_queue_rows',
                'attributes' => [
                    'state' => $state,
                    'creator_ulid' => $creatorUlids[$user->id] ?? null,
                    'name' => $user->name,
                    'email' => $user->email,
                    'requested_at' => $user->deletion_requested_at?->toIso8601String(),
                    'due_at' => $user->deletion_scheduled_for?->toIso8601String(),
                    'days_left' => $this->daysLeft($user->deletion_scheduled_for),
                    'held' => $user->isErasureHeld(),
                    'held_at' => $user->erasure_held_at?->toIso8601String(),
                    'held_by' => $user->erasure_held_by_user_id !== null ? ($holders[$user->erasure_held_by_user_id] ?? null) : null,
                    'claimed_at' => $user->erasure_started_at?->toIso8601String(),
                    'ledger_state' => $run === null ? null : (isset($ledger[$user->ulid]) ? $ledger[$user->ulid]->state->value : 'claimed'),
                    'run' => $run?->toArray(),
                    'blockers' => $blockers,
                ],
            ];
        }

        return $rows;
    }

    /** @param  list<string>  $blockers */
    private function stateOf(User $user, array $blockers): string
    {
        if ($user->erasure_started_at !== null) {
            return self::IN_PROGRESS;
        }
        if ($user->isErasureHeld()) {
            return self::HELD;
        }
        if ($user->deletion_scheduled_for !== null && $user->deletion_scheduled_for->isFuture()) {
            return self::PENDING;
        }

        return $blockers === [] ? self::READY : self::BLOCKED;
    }

    private function daysLeft(?Carbon $due): ?int
    {
        if ($due === null) {
            return null;
        }

        return $due->isFuture() ? (int) ceil(($due->getTimestamp() - now()->getTimestamp()) / 86400) : 0;
    }

    // ── Cursor ──────────────────────────────────────────────────────────────

    /**
     * @param  list<User>  $users  up to perPage + 1 rows
     * @param  callable(list<User>): list<array<string, mixed>>  $present
     * @return array{rows: list<array<string, mixed>>, next_cursor: ?string}
     */
    private function slice(array $users, int $perPage, callable $present): array
    {
        $more = count($users) > $perPage;
        $users = array_slice($users, 0, $perPage);

        return [
            'rows' => $present($users),
            'next_cursor' => $more && $users !== [] ? $this->encode($users[array_key_last($users)]->id) : null,
        ];
    }

    private function encode(int $id): string
    {
        return rtrim(strtr(base64_encode((string) $id), '+/', '-_'), '=');
    }

    private function decode(?string $cursor): ?int
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);

        return $decoded !== false && ctype_digit($decoded) ? (int) $decoded : null;
    }
}
