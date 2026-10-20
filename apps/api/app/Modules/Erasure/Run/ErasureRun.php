<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Run;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Erasure\Enums\ErasureLedgerState;
use App\Modules\Erasure\Execution\AuditScrub;
use App\Modules\Erasure\Execution\ErasureEngine;
use App\Modules\Erasure\Files\FileKeyCollector;
use App\Modules\Erasure\Files\FileStep;
use App\Modules\Erasure\Guards\ErasureRefused;
use App\Modules\Erasure\Guards\Refusals;
use App\Modules\Erasure\Ledger\LedgerMirror;
use App\Modules\Erasure\Ledger\MirrorWriteFailed;
use App\Modules\Erasure\Models\ErasureLedger;
use App\Modules\Erasure\Selection\DryRun;
use App\Modules\Erasure\Subject\SubjectNotErasable;
use App\Modules\Erasure\Subject\SubjectSet;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * One erasure, end to end. This is the only place the stages are put in order.
 *
 * Order, chosen so that a failure at any point leaves the account intact and
 * the run can be started again:
 *
 *   resolve the subject            (reads; the old email exists only until step 6)
 *   refuse                         (payment, vendor; nothing has been touched)
 *   1. audit scrub      (4e)       first, because it needs the identity that step 6 destroys
 *   2. vendors          (4g)       none to call: any real vendor was a refusal above
 *   3. objects          (4f)       keys captured before any write; an already-gone key is done
 *   4. database         (4a-4d)    ONE transaction; the ledger and the audit row commit with it,
 *                                  and so does a closing audit pass (rows written since step 1)
 *   5. ledger mirror   (7)         outside the database, so a restore cannot undo it
 *   6. ledger completed
 *
 * The ledger row is written BEFORE the first step and outside the transaction,
 * so it survives a failure and says how far the run got. A refusal writes
 * nothing, not even a ledger row.
 */
final class ErasureRun
{
    public function __construct(
        private readonly DryRun $dryRun,
        private readonly FileKeyCollector $collector,
        private readonly FileStep $fileStep,
        private readonly AuditScrub $auditScrub,
        private readonly AuditLogger $audit,
        private readonly LedgerMirror $mirror,
    ) {}

    /**
     * What an execute would do now. Reads only, and a refusal is part of the plan
     * rather than an exception, so a dry-run can show it.
     *
     * @throws SubjectNotErasable
     */
    public function plan(User $user): ErasurePlan
    {
        return $this->planFor(SubjectSet::resolve($user));
    }

    /**
     * @throws SubjectNotErasable when the subject cannot be resolved
     * @throws ErasureRefused when a gate fails; nothing was written
     * @throws MirrorWriteFailed after the database stage: the ledger stays db_done and `resume` finishes it
     * @throws Throwable from a step; the ledger row says how far it got
     */
    public function execute(User $user, string $confirmDigest, string $typedUlid, ?User $operator = null): ErasureLedger
    {
        $subject = SubjectSet::resolve($user);
        $plan = $this->planFor($subject);

        $gates = [];
        if (! hash_equals($user->ulid, $typedUlid)) {
            $gates[] = 'the typed ulid does not match the subject';
        }
        if (! hash_equals($plan->digest(), $confirmDigest)) {
            $gates[] = 'the digest does not match the data as it is now: run the dry-run again and confirm the new digest';
        }
        $gates = [...$gates, ...$plan->blockers()];
        if ($gates !== []) {
            throw new ErasureRefused($gates);
        }

        $lock = Cache::lock('erasure-run:'.$user->ulid, 7200);
        if (! $lock->get()) {
            throw new ErasureRefused(['another run for this subject is in progress']);
        }

        try {
            return $this->run($user, $subject, $plan, $operator);
        } finally {
            $lock->release();
        }
    }

    /**
     * Finish a run whose database stage committed but whose last step did not
     * (the process died between the commit and the end). The account is already
     * erased and cannot be resolved again, so this reads the ledger only.
     *
     * @throws ErasureRefused
     * @throws MirrorWriteFailed
     */
    public function resume(User $user): ErasureLedger
    {
        $ledger = ErasureLedger::query()->where('user_ulid', $user->ulid)->where('state', ErasureLedgerState::DbDone)->latest('id')->first();
        if ($ledger === null) {
            throw new ErasureRefused(['there is no run in the db_done state for this subject: nothing to resume']);
        }

        return $this->finish($ledger);
    }

    private function planFor(SubjectSet $subject): ErasurePlan
    {
        return new ErasurePlan(
            $subject,
            $this->dryRun->run($subject),
            $this->collector->collect($subject),
            Refusals::for($subject),
            $this->auditScrub->available(),
        );
    }

    private function run(User $user, SubjectSet $subject, ErasurePlan $plan, ?User $operator): ErasureLedger
    {
        // A run that began and never finished is history, not a live run.
        ErasureLedger::query()->where('user_ulid', $user->ulid)->where('state', ErasureLedgerState::Started)->update(['state' => ErasureLedgerState::Incomplete]);

        $ledger = ErasureLedger::query()->create([
            'user_ulid' => $user->ulid,
            'state' => ErasureLedgerState::Started,
            'digest' => $plan->digest(),
            'run_by_user_id' => $operator?->id,
            'started_at' => now(),
        ]);

        try {
            $scrubbed = $this->auditScrub->run($subject);
            $deletedObjects = $this->fileStep->run($plan->files);

            (new ErasureEngine)->run($subject, null, ErasureEngine::STAGES, function (array $changed) use ($ledger, $user, $operator, $scrubbed, $deletedObjects, $plan, $subject): void {
                // The closing pass. The user is still signed in until this transaction commits, so a
                // request that landed after the first scrub (a login, a failed attempt) wrote an audit
                // row the first pass never saw. This pass runs inside the transaction: it narrows that
                // window to the instant before the commit, and if it fails the erasure rolls back whole.
                $closing = $this->auditScrub->run($subject);
                $scrubbed = $this->sumCounts($scrubbed, $closing);

                $ledger->update([
                    'state' => ErasureLedgerState::DbDone,
                    'counts' => [
                        'database' => $changed,
                        'audit_scrub' => $scrubbed,
                        'files' => $plan->files->summary() + ['deleted' => $deletedObjects],
                    ],
                ]);

                $this->audit->log(
                    action: AuditAction::AccountErased,
                    actor: $operator,
                    subject: $user,
                    reason: 'erasure '.$ledger->ulid,
                    metadata: [
                        'ledger_ulid' => $ledger->ulid,
                        'digest' => $plan->digest(),
                        'counts' => $changed,
                        'files_deleted' => $deletedObjects,
                        'audit_rows_scrubbed' => array_sum($scrubbed),
                    ],
                );
            });
        } catch (Throwable $e) {
            // The database stage is atomic: the ledger update inside it rolled back with it,
            // so the row still reads `started` when nothing committed. Say so.
            $ledger->refresh();
            if ($ledger->state === ErasureLedgerState::Started) {
                $ledger->update(['state' => ErasureLedgerState::Incomplete]);
            }

            throw $e;
        }

        return $this->finish($ledger->refresh());
    }

    /**
     * @param  array<string, int>  $first
     * @param  array<string, int>  $closing
     * @return array<string, int>
     */
    private function sumCounts(array $first, array $closing): array
    {
        foreach ($closing as $key => $n) {
            $first[$key] = ($first[$key] ?? 0) + $n;
        }

        return $first;
    }

    private function finish(ErasureLedger $ledger): ErasureLedger
    {
        $completedAt = $ledger->completed_at ?? now();

        // Outside the database first: if this fails the ledger stays `db_done`, which is
        // exactly what `resume` finishes. Completed is never claimed without the mirror.
        $this->mirror->record($ledger->user_ulid, $completedAt);

        $ledger->update(['state' => ErasureLedgerState::Completed, 'completed_at' => $completedAt]);

        return $ledger;
    }
}
