<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Ledger;

use RuntimeException;

final class MirrorWriteFailed extends RuntimeException
{
    public static function forEntry(): self
    {
        return new self('The ledger mirror could not be written. The database stage is done; run the command again with --execute alone to finish.');
    }
}
