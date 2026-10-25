<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Queue;

use App\Modules\Erasure\Enums\ErasureLedgerState;
use App\Modules\Erasure\Models\ErasureLedger;
use App\Modules\Identity\Models\User;

/**
 * How far a claimed account's erasure run has got, and whether it looks stuck (AH-104c C3, P10).
 *
 * Read from the claim and the account's latest ledger row, nothing else, so a down worker is
 * seen on the queue page and not found by accident. The ONE reader: the queue's rows and the
 * Erase endpoint's "may this be clicked again" both ask here, so the page and the gate cannot
 * disagree.
 *
 *   waiting     claimed, no ledger row yet: the job is queued (or the worker is down)
 *   running     the ledger says `started`
 *   incomplete  the run stopped with a step failed: review the plan and confirm again
 *   db_done     the database stage committed, the ledger mirror did not: finish it from the shell
 *   completed   done
 *
 * `stuck` is true when `waiting` or `running` has lasted longer than a run can (the job timeout
 * plus a margin: {@see STALE_AFTER_SECONDS}), and for `db_done` always (it needs a person).
 * `can_retry` is what the Erase button may do again: only `incomplete`, or a stale
 * `waiting`/`running`. A claimed account whose job is merely queued is never queued twice.
 */
final readonly class ErasureRunStatus
{
    public const WAITING = 'waiting';

    public const RUNNING = 'running';

    public const INCOMPLETE = 'incomplete';

    public const DB_DONE = 'db_done';

    public const COMPLETED = 'completed';

    /** `RunErasureJob::TIMEOUT` (3600) plus five minutes; a spec pins that this stays above it. */
    public const STALE_AFTER_SECONDS = 3900;

    public function __construct(
        public string $state,
        public bool $stuck,
        public bool $canRetry,
    ) {}

    /** Null when the account is not claimed for erasure (or erased with no run to show). */
    public static function for(User $user, ?ErasureLedger $ledger): ?self
    {
        $claimedAt = $user->erasure_started_at;
        if ($claimedAt === null) {
            return null;
        }

        $stale = static fn (?\DateTimeInterface $since): bool => $since !== null
            && now()->getTimestamp() - $since->getTimestamp() > self::STALE_AFTER_SECONDS;

        if ($ledger === null) {
            $stuck = $stale($claimedAt);

            return new self(self::WAITING, $stuck, $stuck);
        }

        return match ($ledger->state) {
            ErasureLedgerState::Started => new self(self::RUNNING, $stale($ledger->started_at), $stale($ledger->started_at)),
            ErasureLedgerState::Incomplete => new self(self::INCOMPLETE, false, true),
            ErasureLedgerState::DbDone => new self(self::DB_DONE, true, false),
            ErasureLedgerState::Completed => new self(self::COMPLETED, false, false),
        };
    }

    /** @return array{state: string, stuck: bool, can_retry: bool} */
    public function toArray(): array
    {
        return ['state' => $this->state, 'stuck' => $this->stuck, 'can_retry' => $this->canRetry];
    }
}
