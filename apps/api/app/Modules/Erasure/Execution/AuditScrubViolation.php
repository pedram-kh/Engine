<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Execution;

use App\Modules\Erasure\Selection\AuditRowScrub;
use LogicException;

/**
 * The scrub tried to write something it must never write. A programming error,
 * not a data problem: raised before any statement is sent.
 */
final class AuditScrubViolation extends LogicException
{
    /** @param list<string> $columns */
    public static function forColumns(array $columns): self
    {
        return new self('The audit scrub may only write '.implode(', ', AuditRowScrub::COLUMNS).'; refused: '.implode(', ', $columns).'.');
    }

    public static function noRow(): self
    {
        return new self('The audit scrub writes one row at a time, by id.');
    }
}
