<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Exceptions;

use App\Modules\Campaigns\Enums\OfferLockReason;
use RuntimeException;

/**
 * An offer edit that cannot proceed. `errorCode` is the stable envelope code the
 * controller maps to a 422; `reason` is set only for `assignment.offer_locked`.
 */
final class AssignmentOfferEditException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?OfferLockReason $reason = null,
    ) {
        parent::__construct($message);
    }

    public static function locked(OfferLockReason $reason): self
    {
        return new self(
            'assignment.offer_locked',
            "This offer can no longer be edited ({$reason->value}).",
            $reason,
        );
    }

    public static function unchanged(): self
    {
        return new self('assignment.offer_unchanged', 'The new amount is the same as the current offer.');
    }
}
