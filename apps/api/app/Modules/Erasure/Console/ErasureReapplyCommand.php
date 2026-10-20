<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Console;

use App\Modules\Erasure\Execution\AuditScrubUnavailable;
use App\Modules\Erasure\Files\ObjectDeletionFailed;
use App\Modules\Erasure\Guards\ErasureRefused;
use App\Modules\Erasure\Ledger\MirrorWriteFailed;
use App\Modules\Erasure\Run\ErasureReapply;
use App\Modules\Erasure\Subject\SubjectNotErasable;
use App\Modules\Identity\Models\User;
use Illuminate\Console\Command;
use Throwable;

/**
 * `php artisan erasure:reapply` — after a database restore, re-erase everyone the
 * ledger mirror (outside the database) says was erased and the restored database
 * does not know about.
 *
 * Dry-run by default; execute needs the combined digest and the number of people.
 * Prints ulids, counts and table names only, never an email, a name or a key.
 * Never scheduled, no route.
 */
final class ErasureReapplyCommand extends Command
{
    protected $signature = 'erasure:reapply
        {--execute : Re-erase the listed people. Without this flag only the list is printed}
        {--confirm-digest= : The combined digest printed by the dry-run}
        {--confirm-count= : The number of people the dry-run listed}
        {--operator= : The ulid of the operator, recorded in the ledger and the audit rows}';

    protected $description = 'After a restore: list (default) or re-erase everyone the ledger mirror says was erased. Irreversible; never scheduled.';

    public function handle(ErasureReapply $reapply): int
    {
        $operator = null;
        $operatorUlid = $this->option('operator');
        if (is_string($operatorUlid) && $operatorUlid !== '') {
            $operator = User::query()->where('ulid', strtoupper($operatorUlid))->first();
            if ($operator === null) {
                $this->error('No user has the operator ulid. Nothing was read or written.');

                return self::FAILURE;
            }
        }

        $plan = $reapply->plan();

        $this->line('Ledger mirror: '.$plan->mirrorEntries.' erasure(s) recorded; '.$plan->alreadyErased.' already erased here, '.$plan->unknownUsers.' not in this database, '.count($plan->subjects).' to re-erase.');
        foreach ($plan->subjects as $subject) {
            $this->line('  '.$subject['user']->ulid.'  rows '.$subject['plan']->report->total().', objects '.$subject['plan']->files->count().', digest '.$subject['plan']->digest());
        }
        foreach ($plan->blockers() as $blocker) {
            $this->warn('REFUSED: '.$blocker);
        }
        $this->line('Digest: '.$plan->digest());

        if ($this->option('execute') !== true) {
            if ($plan->blockers() !== []) {
                $this->error('An execute would be refused. Nothing was changed.');

                return self::FAILURE;
            }
            $this->line('Dry-run only. To execute: --execute --confirm-digest='.$plan->digest().' --confirm-count='.count($plan->subjects));

            return self::SUCCESS;
        }

        $digest = $this->option('confirm-digest');
        $count = $this->option('confirm-count');
        if (! is_string($digest) || $digest === '' || ! is_string($count) || filter_var($count, FILTER_VALIDATE_INT) === false) {
            $this->error('--execute needs --confirm-digest and --confirm-count. Nothing was changed.');

            return self::FAILURE;
        }

        try {
            $done = $reapply->execute($digest, (int) $count, $operator);
        } catch (ErasureRefused|SubjectNotErasable|ObjectDeletionFailed|AuditScrubUnavailable|MirrorWriteFailed $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('The reapply failed ('.class_basename($e).'). Run the dry-run again to see who is left; re-running is safe.');

            return self::FAILURE;
        }

        $this->info('Re-erased '.count($done).' person(s).');

        return self::SUCCESS;
    }
}
