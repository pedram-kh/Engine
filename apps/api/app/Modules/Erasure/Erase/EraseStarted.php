<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Erase;

use App\Modules\Erasure\Claim\ErasureClaim;

/** The click was accepted: the account is claimed and the run is queued (`$queued`), or a retry was queued. */
final readonly class EraseStarted
{
    public function __construct(public ErasureClaim $claim, public bool $retry, public bool $queued) {}
}
