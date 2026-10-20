<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Execution;

use RuntimeException;

final class AuditScrubUnavailable extends RuntimeException
{
    public static function notAvailable(): self
    {
        return new self('The audit scrub (stage 4e) is not available here, so the run stops before it changes anything.');
    }
}
