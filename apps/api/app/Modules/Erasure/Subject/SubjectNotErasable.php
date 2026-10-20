<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Subject;

use RuntimeException;

/**
 * The subject cannot be resolved into an erasure subject set. Raised before
 * anything is read beyond the subject's own two rows, and never after a write.
 */
final class SubjectNotErasable extends RuntimeException
{
    public static function notACreator(): self
    {
        return new self('The subject is not a creator user: erasure only resolves creator accounts.');
    }

    public static function noCreatorRow(): self
    {
        return new self('The subject user has no creators row: nothing to resolve a subject set from.');
    }

    public static function alreadyErased(): self
    {
        return new self('The subject is already erased (erased_at is set): the old email no longer exists to resolve rows keyed by it.');
    }
}
