<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Selection;

use App\Modules\Erasure\Registry\Disposition;
use App\Modules\Erasure\Subject\SubjectSet;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use stdClass;

/**
 * One predicate of the erasure: "these rows of this table, for this subject".
 *
 * The dry-run COUNTS a selection; the Stage 4 step that executes it updates or
 * deletes exactly the rows the same selection returns. One definition for both
 * is what makes "the dry-run's counts equal the real run's counts" a property
 * of the design instead of a hope.
 *
 * A selection returns only rows that still need changing, so a second run finds
 * nothing (idempotence): a row already anonymised is not selected again. When
 * the need cannot be expressed in SQL (an encrypted column, a key inside JSON)
 * `needsChange` decides per row, in PHP, on the rows the query returned.
 */
final readonly class Selection
{
    /**
     * @param  string  $id  Stable name, printed in the report: `table` or `table.predicate`.
     * @param  string  $stage  The plan sub-step that executes it (4a ... 4e).
     * @param  Closure(SubjectSet, ConnectionInterface): Builder  $query
     * @param  (Closure(stdClass, SubjectSet): bool)|null  $needsChange
     */
    public function __construct(
        public string $id,
        public string $table,
        public Disposition $disposition,
        public string $stage,
        public string $description,
        private Closure $query,
        private ?Closure $needsChange = null,
    ) {}

    /** @param ConnectionInterface $db The connection to build against (a spec compiles the same query for pgsql without connecting). */
    public function builder(SubjectSet $subject, ConnectionInterface $db): Builder
    {
        return ($this->query)($subject, $db);
    }

    /** Whether a row the query returned still needs its change. */
    public function rowNeedsChange(stdClass $row, SubjectSet $subject): bool
    {
        return $this->needsChange === null || ($this->needsChange)($row, $subject);
    }

    public function isFiltered(): bool
    {
        return $this->needsChange !== null;
    }

    /** Rows that WOULD change. Reads only. */
    public function count(SubjectSet $subject, ConnectionInterface $db): int
    {
        $query = $this->builder($subject, $db);
        if ($this->needsChange === null) {
            return $query->count();
        }

        $n = 0;
        foreach ($query->cursor() as $row) {
            if ($row instanceof stdClass && $this->rowNeedsChange($row, $subject)) {
                $n++;
            }
        }

        return $n;
    }
}
