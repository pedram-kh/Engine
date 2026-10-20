<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Run;

use App\Modules\Erasure\Execution\AuditScrubUnavailable;
use App\Modules\Erasure\Files\ObjectDeletionFailed;
use App\Modules\Erasure\Guards\ErasureRefused;
use App\Modules\Erasure\Ledger\LedgerMirror;
use App\Modules\Erasure\Ledger\MirrorWriteFailed;
use App\Modules\Erasure\Subject\SubjectNotErasable;
use App\Modules\Identity\Models\User;

/**
 * Re-erase, after a restore, everyone the ledger mirror says was erased and the
 * restored database does not.
 *
 * A restore brings back a snapshot taken before some erasures: the old email,
 * the name, the files' rows. The mirror is outside the database, so it still
 * lists them. This is the same engine, with the same refusals and the same fresh
 * digest, run once per such user. The batch is confirmed by its combined digest
 * and by the number of people in it (the typed-ulid check of a single run, for a
 * list): an operator cannot approve a list they have not counted.
 *
 * Entries for a user this database does not have (the snapshot predates the
 * account) or that is already erased are counted and left alone.
 */
final class ErasureReapply
{
    public function __construct(private readonly LedgerMirror $mirror, private readonly ErasureRun $run) {}

    public function plan(): ReapplyPlan
    {
        $subjects = [];
        $unknown = 0;
        $alreadyErased = 0;
        $entries = $this->mirror->entries();

        foreach ($entries as $entry) {
            $user = User::withTrashed()->where('ulid', $entry->userUlid)->first();
            if ($user === null) {
                $unknown++;

                continue;
            }
            if ($user->isErased()) {
                $alreadyErased++;

                continue;
            }
            $subjects[] = ['user' => $user, 'plan' => $this->run->plan($user)];
        }

        return new ReapplyPlan(count($entries), $unknown, $alreadyErased, $subjects);
    }

    /**
     * @return list<string> the ulids re-erased
     *
     * @throws ErasureRefused when a gate fails; nothing was written
     * @throws SubjectNotErasable
     * @throws ObjectDeletionFailed
     * @throws AuditScrubUnavailable
     * @throws MirrorWriteFailed
     */
    public function execute(string $confirmDigest, int $confirmCount, ?User $operator = null): array
    {
        $plan = $this->plan();

        $gates = [];
        if ($confirmCount !== count($plan->subjects)) {
            $gates[] = 'the confirmed count does not match the number of people to re-erase';
        }
        if (! hash_equals($plan->digest(), $confirmDigest)) {
            $gates[] = 'the digest does not match the data as it is now: run the dry-run again and confirm the new digest';
        }
        $gates = [...$gates, ...$plan->blockers()];
        if ($gates !== []) {
            throw new ErasureRefused($gates);
        }

        $done = [];
        foreach ($plan->subjects as $subject) {
            // Same gates per subject (fresh digest, typed ulid); the batch confirmation above is the operator's.
            $this->run->execute($subject['user'], $subject['plan']->digest(), $subject['user']->ulid, $operator);
            $done[] = $subject['user']->ulid;
        }

        return $done;
    }
}
