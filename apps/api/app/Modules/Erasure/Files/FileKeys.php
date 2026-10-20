<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Files;

/**
 * The object keys an erasure would delete, captured BEFORE any database write
 * (the keys live in rows the database stage deletes or nulls).
 *
 * The keys are never printed: a key can carry the creator's ulid and, in a
 * shared prefix, a thread's. {@see summary()} gives counts per class and
 * {@see digest()} a hash, so a dry-run proves WHICH objects without naming them.
 */
final readonly class FileKeys
{
    /**
     * @param  list<string>  $keys  sorted, unique
     * @param  array<string, int>  $byClass  class => keys, sorted by class
     * @param  int  $skipped  keys the database named that were NOT deleted because they sit outside the subject's own prefix
     */
    public function __construct(public array $keys, public array $byClass, public int $skipped) {}

    /** @return array<string, int> */
    public function summary(): array
    {
        return $this->byClass + ['skipped_outside_own_prefix' => $this->skipped];
    }

    public function count(): int
    {
        return count($this->keys);
    }

    public function digest(): string
    {
        return hash('sha256', json_encode([
            'keys' => hash('sha256', implode("\n", $this->keys)),
            'classes' => $this->byClass,
            'skipped' => $this->skipped,
        ], JSON_THROW_ON_ERROR));
    }
}
