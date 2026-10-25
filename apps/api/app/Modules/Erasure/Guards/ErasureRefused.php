<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Guards;

use RuntimeException;

/**
 * The run is refused before it writes or deletes anything. The reasons name a
 * table, a provider or a count, never a person.
 *
 * `$dataMoved` is true when the ONLY thing wrong is that the digest the admin confirmed no longer
 * matches the data (it moved between the plan and the run). That is a normal outcome, not a
 * refusal of the account: the queued job releases the claim and says "review the plan again".
 */
final class ErasureRefused extends RuntimeException
{
    /** @param list<string> $reasons */
    public function __construct(public readonly array $reasons, public readonly bool $dataMoved = false)
    {
        parent::__construct('Erasure refused: '.implode('; ', $reasons));
    }
}
