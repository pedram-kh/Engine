<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Guards;

use RuntimeException;

/**
 * The run is refused before it writes or deletes anything. The reasons name a
 * table, a provider or a count, never a person.
 */
final class ErasureRefused extends RuntimeException
{
    /** @param list<string> $reasons */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct('Erasure refused: '.implode('; ', $reasons));
    }
}
