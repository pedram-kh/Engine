<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Ledger;

use Carbon\CarbonInterface;

/**
 * The write-through copy of the erasure ledger that lives OUTSIDE the database.
 *
 * A table cannot protect the database from a restore: restoring a snapshot taken
 * before an erasure restores the ledger as it was before it, so the record that
 * says "erase this creator again" is gone with the erasure. This copy survives
 * the restore. After one, `erasure:reapply` lists every entry whose user is not
 * erased in the restored database and runs the engine for each.
 *
 * Holds a pseudonymous `users.ulid` and a timestamp, nothing else, and there is
 * deliberately no way to remove an entry from the application.
 */
interface LedgerMirror
{
    /**
     * Record that an erasure completed. Idempotent: an existing entry is kept
     * (the first completion time is the true one).
     *
     * @throws MirrorWriteFailed
     */
    public function record(string $userUlid, CarbonInterface $completedAt): void;

    /**
     * Every readable entry, oldest name first.
     *
     * @return list<MirrorEntry>
     */
    public function entries(): array;
}
