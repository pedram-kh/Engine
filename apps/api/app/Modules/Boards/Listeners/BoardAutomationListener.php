<?php

declare(strict_types=1);

namespace App\Modules\Boards\Listeners;

use App\Modules\Boards\Services\BoardAutomationService;
use App\Modules\Campaigns\Events\AssignmentEventContract;
use App\Modules\Campaigns\Events\AssignmentTransitioned;

/**
 * The 7th consumer of {@see AssignmentTransitioned}
 * (Sprint 12 Chunk 1, D-6). Per docs/10-BOARD-AUTOMATION.md §5.2.
 *
 * The headline reconciliation: §2.1/§5.2 sketches dedicated event classes
 * (AssignmentDraftApproved etc.), but Sprint 8 deliberately built the single
 * {@see AssignmentTransitioned} keyed by the
 * AuditAction value, with {@see AssignmentEventContract} exposing exactly
 * assignment()/eventKey()/metadata()/triggeredByUserId() — the four things the
 * §5.2 listener needs. The dedicated-class sketch is SUPERSEDED: this listener
 * binds to the CONTRACT and switches on eventKey(); no per-event classes.
 *
 * Registered AFTER {@see CreateBoardCard} (D-7) so the card exists before the
 * `invited → Invited` automation runs; the service is ALSO a no-op on a missing
 * card (belt + suspenders).
 */
final class BoardAutomationListener
{
    public function __construct(private readonly BoardAutomationService $service) {}

    public function handle(object $event): void
    {
        if (! $event instanceof AssignmentEventContract) {
            return;
        }

        $this->service->processEvent(
            assignmentId: $event->assignment()->id,
            eventKey: $event->eventKey(),
            metadata: $event->metadata(),
            triggeredByUserId: $event->triggeredByUserId(),
        );
    }
}
