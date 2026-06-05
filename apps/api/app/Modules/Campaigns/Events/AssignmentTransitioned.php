<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Events;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Campaigns\Services\CampaignAssignmentStateMachine;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched by {@see CampaignAssignmentStateMachine}
 * on every legal transition (Sprint 8 Chunk 1, D-9).
 *
 * A single parametrized event carries the from/to states + the board
 * event-key (via the {@see AuditAction} verb, whose string value IS the board
 * key). The future board listener only depends on {@see AssignmentEventContract}
 * — it switches on {@see self::eventKey()} — so one event class is sufficient
 * and keeps the board sprint purely additive. NO listener is registered this
 * chunk.
 */
final class AssignmentTransitioned implements AssignmentEventContract
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly CampaignAssignment $assignment,
        public readonly AssignmentStatus $from,
        public readonly AssignmentStatus $to,
        public readonly AuditAction $action,
        public readonly ?int $triggeredByUserId = null,
        public readonly array $context = [],
    ) {}

    public function assignment(): CampaignAssignment
    {
        return $this->assignment;
    }

    public function eventKey(): string
    {
        return $this->action->value;
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return array_merge(
            ['from' => $this->from->value, 'to' => $this->to->value],
            $this->context,
        );
    }

    public function triggeredByUserId(): ?int
    {
        return $this->triggeredByUserId;
    }
}
