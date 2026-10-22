<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Console;

use App\Modules\Erasure\Claim\ClaimMode;
use App\Modules\Erasure\Claim\ErasureClaimer;
use App\Modules\Erasure\Execution\AuditScrubUnavailable;
use App\Modules\Erasure\Execution\DatabaseAuditScrub;
use App\Modules\Erasure\Files\ObjectDeletionFailed;
use App\Modules\Erasure\Guards\ErasureRefused;
use App\Modules\Erasure\Ledger\MirrorWriteFailed;
use App\Modules\Erasure\Run\ErasurePlan;
use App\Modules\Erasure\Run\ErasureRun;
use App\Modules\Erasure\Subject\SubjectNotErasable;
use App\Modules\Identity\Models\User;
use Illuminate\Console\Command;
use Throwable;

/**
 * `php artisan erasure:run {user_ulid}` — erase one creator's personal data.
 *
 * Dry-run by default. Execute needs the digest the dry-run printed AND the
 * subject's ulid typed again. It is never scheduled and has no route: the only
 * way in is a shell on a machine with production credentials, as a fallback to
 * the admin Erase button (AH-104c), with the dry-run output attached to the
 * request.
 *
 * AH-104c: it asks the SAME gate as the button ({@see ErasureClaimer}): the account
 * must have asked to be deleted and its grace must have ended, it must not be on hold,
 * and no guard may refuse. The dry-run prints that verdict. Execute claims the account
 * (the creator can no longer cancel) before the first irreversible step.
 * `--force-not-requested` is the one way past "has not asked" and "not due": it needs
 * `--operator` and `--reason`, writes its own audit reason, and never passes a hold.
 *
 * Output is counts, table names and object counts. It never prints an email, a
 * name, an ip or an object key, and on a failure it prints the exception class,
 * not its message (a database message can carry the bound values).
 */
final class ErasureRunCommand extends Command
{
    protected $signature = 'erasure:run
        {user_ulid : The ulid of the creator user to erase}
        {--execute : Perform the erasure. Without this flag only a dry-run is printed}
        {--confirm-digest= : The digest printed by the dry-run, recomputed fresh at execute time}
        {--confirm-ulid= : The subject ulid typed again}
        {--operator= : The ulid of the operator, recorded in the ledger and the audit row}
        {--force-not-requested : Erase an account that never asked, or whose grace has not ended. Needs --operator and --reason; never passes a hold}
        {--reason= : With --force-not-requested: why. Kept in the audit row after the erasure, so write no personal details}';

    protected $description = 'Dry-run (default) or execute the erasure of one creator account. Irreversible; never scheduled.';

    public function handle(ErasureRun $run, ErasureClaimer $claimer): int
    {
        $user = User::withTrashed()->where('ulid', strtoupper(trim((string) $this->argument('user_ulid'))))->first();
        if ($user === null) {
            $this->error('No user has that ulid. Nothing was read or written.');

            return self::FAILURE;
        }

        $operator = null;
        $operatorUlid = $this->option('operator');
        if (is_string($operatorUlid) && $operatorUlid !== '') {
            $operator = User::query()->where('ulid', strtoupper($operatorUlid))->first();
            if ($operator === null) {
                $this->error('No user has the operator ulid. Nothing was read or written.');

                return self::FAILURE;
            }
        }

        if ($user->isErased()) {
            return $this->option('execute') === true ? $this->resume($run, $user) : $this->alreadyErased();
        }

        $mode = $this->option('force-not-requested') === true ? ClaimMode::ForceNotRequested : ClaimMode::Normal;
        $reason = $this->reasonFor($mode);
        if ($mode === ClaimMode::ForceNotRequested && ($operator === null || $reason === null)) {
            $this->error('--force-not-requested needs --operator and --reason. Nothing was read or written.');

            return self::FAILURE;
        }

        try {
            $plan = $run->plan($user);
        } catch (SubjectNotErasable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->printPlan($plan);

        // The gate's own legs (a hold, a request whose grace has ended), printed so the verdict
        // is read before anything is typed. The guards' refusals are the REFUSED lines above.
        $gateFailures = $claimer->requestGates($user, $mode);
        foreach ($gateFailures as $failure) {
            $this->warn('NOT READY: '.$failure);
        }
        if ($user->isErasureClaimed()) {
            $this->line('Claimed: yes (since '.$user->erasure_started_at?->toDateTimeString().'). A claimed account is not asked again; the run is retried.');
            $gateFailures = [];
        }

        if ($this->option('execute') !== true) {
            if ($plan->blockers() !== [] || $gateFailures !== []) {
                $this->error('An execute would be refused. Nothing was changed.');

                return self::FAILURE;
            }
            $this->line('Dry-run only. To execute: --execute --confirm-digest='.$plan->digest().' --confirm-ulid='.$user->ulid.($mode === ClaimMode::ForceNotRequested ? ' --force-not-requested --operator='.$operator->ulid.' --reason=...' : ''));

            return self::SUCCESS;
        }

        $digest = $this->option('confirm-digest');
        $typed = $this->option('confirm-ulid');
        if (! is_string($digest) || $digest === '' || ! is_string($typed) || $typed === '') {
            $this->error('--execute needs --confirm-digest and --confirm-ulid. Nothing was changed.');

            return self::FAILURE;
        }

        // A stale digest or a mistyped ulid is refused HERE, before the claim: nothing is taken.
        if (! hash_equals($plan->digest(), $digest) || ! hash_equals($user->ulid, $typed)) {
            $this->error('The digest or the typed ulid does not match: run the dry-run again and confirm what it prints. Nothing was changed.');

            return self::FAILURE;
        }

        try {
            $claim = $claimer->claim($user, $operator, $mode, $reason ?? 'shell erasure: the grace has ended');
            $ledger = $run->execute($user, $claim, $digest, $typed, $operator);
        } catch (ErasureRefused $e) {
            // Refused before anything ran: hand the account back (the claimer refuses when a run began).
            $claimer->release($user, $operator, 'refused');
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (SubjectNotErasable|ObjectDeletionFailed|AuditScrubUnavailable|MirrorWriteFailed $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('The run failed ('.class_basename($e).'). Read the ledger row for how far it got; re-running is safe. If the ledger is db_done, run again with --execute alone to finish.');

            return self::FAILURE;
        }

        $this->info('Erased. Ledger '.$ledger->ulid.' is '.$ledger->state->value.'.');

        return self::SUCCESS;
    }

    /** The audit reason: fixed for the normal gate, the operator's own words (prefixed) when forcing. */
    private function reasonFor(ClaimMode $mode): ?string
    {
        if ($mode === ClaimMode::Normal) {
            return 'shell erasure: the grace has ended';
        }
        $given = $this->option('reason');

        return is_string($given) && trim($given) !== '' ? 'forced, not requested: '.trim($given) : null;
    }

    private function resume(ErasureRun $run, User $user): int
    {
        try {
            $ledger = $run->resume($user);
        } catch (ErasureRefused|MirrorWriteFailed $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Resumed. Ledger '.$ledger->ulid.' is '.$ledger->state->value.'.');

        return self::SUCCESS;
    }

    private function alreadyErased(): int
    {
        $this->error('The subject is already erased. To finish a run that stopped after the database stage, use --execute.');

        return self::FAILURE;
    }

    private function printPlan(ErasurePlan $plan): void
    {
        $this->line('Erasure plan for user '.$plan->subject->userUlid.' (counts only; no name, email or object key is printed)');
        $this->table(['stage', 'selection', 'table', 'disposition', 'rows', 'read'], array_map(
            fn (array $l): array => [$l['stage'], $l['id'], $l['table'], $l['disposition'], $l['count'], $l['read']],
            $plan->report->lines,
        ));
        $cost = $plan->report->cost(DatabaseAuditScrub::CHUNK);
        $this->line('Cost: '.$cost['rows'].' rows read in about '.$cost['batches'].' batches of '.DatabaseAuditScrub::CHUNK.'; largest read: '.($cost['largest'] === '' ? 'none' : $cost['largest'].' ('.$cost['largest_rows'].' rows)').'. "read" is what a line must scan to find its rows; "rows" is what it changes.');
        $this->line('Objects to delete: '.$plan->files->count().' ('.json_encode($plan->files->summary(), JSON_THROW_ON_ERROR).')');
        $this->line('Vendor calls: 0 (any non-mock provider is a refusal)');
        if (config('erasure.purge_object_versions') !== true) {
            $this->warn('purge_object_versions is OFF: stored versions of deleted objects will remain readable.');
        }
        $this->line('Audit scrub (4e): '.($plan->auditScrubAvailable ? 'available' : 'NOT AVAILABLE'));
        foreach ($plan->blockers() as $blocker) {
            $this->warn('REFUSED: '.$blocker);
        }
        $this->line('Digest: '.$plan->digest());
    }
}
