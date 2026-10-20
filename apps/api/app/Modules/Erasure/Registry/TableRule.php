<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Registry;

/**
 * One table's decision.
 *
 * - `columns` empty: a table-level decision only (`NotApplicable` or `Expire`);
 *   `classified` then names every PII-shaped column with the reason it is not
 *   creator data.
 * - `columns` non-empty: column level; the schema must not have a column the
 *   registry does not name.
 *
 * `refs` is the exact set of columns that are foreign keys to `users` or
 * `creators`, declared so a new foreign key on ANY table (including an N/A one)
 * fails the verifier until someone decides what it means.
 */
final readonly class TableRule
{
    /**
     * @param  ?Disposition  $disposition  Table-level code; null for a column-wise table.
     * @param  list<string>  $refs  Columns that are FKs to users or creators.
     * @param  array<string, ColumnRule>  $columns
     * @param  array<string, string>  $classified  PII-shaped column => why it is not creator data.
     */
    public function __construct(
        public ?Disposition $disposition,
        public string $reason,
        public array $refs = [],
        public array $columns = [],
        public ?string $predicate = null,
        public array $classified = [],
    ) {}

    public function isColumnLevel(): bool
    {
        return $this->columns !== [];
    }
}
