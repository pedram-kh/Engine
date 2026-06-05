<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Exceptions;

use App\Core\Errors\ErrorResponse;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Services\CampaignAssignmentStateMachine;
use RuntimeException;

/**
 * Thrown by {@see CampaignAssignmentStateMachine}
 * when a transition is illegal (Sprint 8 Chunk 1, D-5 — fail-closed).
 *
 * Carries a stable `errorCode` so the controller layer (the creator-side
 * accept/decline/counter endpoints land in Chunk 2) can map it to a typed
 * 422 via {@see ErrorResponse}. Allowed codes:
 *
 *   assignment.invalid_transition  — the source state cannot reach the target.
 *   assignment.terminal            — the assignment is already terminal.
 *   assignment.reason_required     — cancel was called without a reason.
 */
class AssignmentTransitionException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function illegal(AssignmentStatus $from, AssignmentStatus $to): self
    {
        return new self(
            'assignment.invalid_transition',
            "Illegal assignment transition: {$from->value} → {$to->value}.",
        );
    }

    public static function terminal(AssignmentStatus $from): self
    {
        return new self(
            'assignment.terminal',
            "Cannot transition a terminal assignment (status: {$from->value}).",
        );
    }

    public static function reasonRequired(): self
    {
        return new self(
            'assignment.reason_required',
            'Cancelling an assignment requires a non-empty reason.',
        );
    }
}
