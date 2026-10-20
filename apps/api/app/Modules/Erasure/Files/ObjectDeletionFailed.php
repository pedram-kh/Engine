<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Files;

use RuntimeException;

/**
 * An object could not be deleted. The message names no key (a key can carry a
 * creator ulid and is not printed), only how many deletions had succeeded.
 */
final class ObjectDeletionFailed extends RuntimeException
{
    public static function forObject(?\Throwable $previous = null): self
    {
        return new self('An object could not be deleted from storage; the run stops before any database change.', 0, $previous);
    }
}
