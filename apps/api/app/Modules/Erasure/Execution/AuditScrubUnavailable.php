<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Execution;

use RuntimeException;

final class AuditScrubUnavailable extends RuntimeException
{
    public static function noScrubConnection(): self
    {
        return new self('The audit scrub (stage 4e) is not available: no scrub connection is configured. It does not fall back to the application role.');
    }
}
