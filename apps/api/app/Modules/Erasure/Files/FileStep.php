<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Files;

/**
 * Deletes the captured keys, one at a time, stopping at the first failure.
 * It runs BEFORE the database stage, so a failure leaves every row in place and
 * the run can simply be started again: a key already gone counts as done.
 */
final class FileStep
{
    public function __construct(private readonly ObjectStore $store) {}

    /** @return int objects deleted by this call */
    public function run(FileKeys $keys): int
    {
        $deleted = 0;
        foreach ($keys->keys as $key) {
            $this->store->delete($key);
            $deleted++;
        }

        return $deleted;
    }
}
