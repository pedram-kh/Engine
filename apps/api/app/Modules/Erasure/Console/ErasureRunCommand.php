<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Console;

use App\Modules\Erasure\Execution\AuditScrubUnavailable;
use App\Modules\Erasure\Files\ObjectDeletionFailed;
use App\Modules\Erasure\Guards\ErasureRefused;
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
        {--operator= : The ulid of the operator, recorded in the ledger and the audit row}';

    protected $description = 'Dry-run (default) or execute the erasure of one creator account. Irreversible; never scheduled.';

    public function handle(ErasureRun $run): int
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

        try {
            $plan = $run->plan($user);
        } catch (SubjectNotErasable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->printPlan($plan);

        if ($this->option('execute') !== true) {
            if ($plan->blockers() !== []) {
                $this->error('An execute would be refused. Nothing was changed.');

                return self::FAILURE;
            }
            $this->line('Dry-run only. To execute: --execute --confirm-digest='.$plan->digest().' --confirm-ulid='.$user->ulid);

            return self::SUCCESS;
        }

        $digest = $this->option('confirm-digest');
        $typed = $this->option('confirm-ulid');
        if (! is_string($digest) || $digest === '' || ! is_string($typed) || $typed === '') {
            $this->error('--execute needs --confirm-digest and --confirm-ulid. Nothing was changed.');

            return self::FAILURE;
        }

        try {
            $ledger = $run->execute($user, $digest, $typed, $operator);
        } catch (ErasureRefused|SubjectNotErasable|ObjectDeletionFailed|AuditScrubUnavailable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('The run failed ('.class_basename($e).'). Read the ledger row for how far it got; re-running is safe.');

            return self::FAILURE;
        }

        $this->info('Erased. Ledger '.$ledger->ulid.' is '.$ledger->state->value.'.');

        return self::SUCCESS;
    }

    private function resume(ErasureRun $run, User $user): int
    {
        try {
            $ledger = $run->resume($user);
        } catch (ErasureRefused $e) {
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
        $this->table(['stage', 'selection', 'table', 'disposition', 'rows'], array_map(
            fn (array $l): array => [$l['stage'], $l['id'], $l['table'], $l['disposition'], $l['count']],
            $plan->report->lines,
        ));
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
