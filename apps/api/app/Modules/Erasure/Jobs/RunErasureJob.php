<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Jobs;

use App\Modules\Erasure\Claim\ErasureClaimer;
use App\Modules\Erasure\Guards\ErasureRefused;
use App\Modules\Erasure\Run\ErasureRun;
use App\Modules\Erasure\Subject\SubjectNotErasable;
use App\Modules\Identity\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The erasure run, queued by the admin's Erase click (AH-104c C3). The ONLY queued path to
 * `ErasureRun::execute()`; the other is the `erasure:run` command.
 *
 * ── Where it runs ───────────────────────────────────────────────────────────
 *
 * Connection `database_erasure`, queue `erasure`, read ONLY by a dedicated worker
 * (`queue:work database_erasure --queue=erasure --tries=1 --timeout=3600`, runbook
 * `production-queue-worker.md` §2a). Never the default worker: its `retry_after` (300 s) is shorter
 * than a run, so a long run would be delivered a second time while the first is still working
 * (P1). `$tries = 1`: a failed run is never retried by the queue; an admin re-plans and confirms.
 *
 * ── What it does with each ending ───────────────────────────────────────────
 *
 *   - the run completes: nothing more; the claim stays (the account is erased);
 *   - a refusal or a moved digest (P8): nothing was written, so the claim is RELEASED with an audit
 *     row (`account.erasure_claim_released`, cause `data_changed` or `refused`) and the creator may
 *     cancel again. `release()` itself refuses to reopen an account once a ledger row exists;
 *   - ANY other exception: the claim STAYS. The files may already be gone, so the creator must not
 *     be handed back an account with no files. The ledger says how far it got; `failed()` only logs.
 *
 * IDs and the digest travel, never models (a serialized model re-resolves through a tenancy scope
 * in a tenant-less worker). The typed ulid was checked at the click; the job passes the subject's
 * own ulid, which the engine compares again to the claim.
 */
final class RunErasureJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const CONNECTION = 'database_erasure';

    public const QUEUE = 'erasure';

    /** Seconds. `config/queue.php` `database_erasure.retry_after` MUST stay above this. */
    public const TIMEOUT = 3600;

    public int $tries = 1;

    public int $timeout = self::TIMEOUT;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly string $userUlid,
        public readonly int $operatorId,
        public readonly string $digest,
    ) {
        $this->onConnection(self::CONNECTION);
        $this->onQueue(self::QUEUE);
    }

    public function handle(ErasureRun $run, ErasureClaimer $claimer): void
    {
        $user = User::query()->withTrashed()->where('ulid', $this->userUlid)->first();
        if ($user === null) {
            Log::warning('erasure.job.skipped', ['reason' => 'no_user', 'user_ulid' => $this->userUlid]);

            return;
        }
        $operator = User::query()->whereKey($this->operatorId)->first();

        // A duplicate delivery after the run finished (or a second job from a retry click): nothing to do,
        // except a run that stopped after its database stage, which this finishes.
        if ($user->isErased()) {
            try {
                $run->resume($user);
            } catch (ErasureRefused) {
                // No db_done run: it is already complete, or was never ours. Nothing to finish.
            }

            return;
        }

        try {
            $claim = $claimer->resume($user);
            $run->execute($user, $claim, $this->digest, $user->ulid, $operator);
        } catch (ErasureRefused $e) {
            $claimer->release($user, $operator, $e->dataMoved ? 'data_changed' : 'refused');
            Log::info('erasure.job.refused', ['user_ulid' => $this->userUlid, 'data_moved' => $e->dataMoved]);
        } catch (SubjectNotErasable) {
            $claimer->release($user, $operator, 'refused');
        }
    }

    public function failed(Throwable $exception): void
    {
        // The claim stays on purpose. Class name only: an exception message could carry a value.
        Log::error('erasure.job.failed', ['user_ulid' => $this->userUlid, 'exception' => $exception::class]);
    }
}
