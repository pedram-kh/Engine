<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Events;

use App\Modules\Campaigns\Models\CampaignAssignment;

/**
 * Contract implemented by every CampaignAssignment state-machine transition
 * event (Sprint 8 Chunk 1, D-9 — the deferral discipline).
 *
 * The board subsystem (its OWN sprint, after Sprint 8) will register a single
 * listener that subscribes to this contract and moves Kanban cards based on
 * {@see self::eventKey()} (see docs/10-BOARD-AUTOMATION.md §5.2). Sprint 8
 * Chunk 1 DISPATCHES these events but builds NO listener — leaving the board
 * sprint purely additive (it adds the listener + Kanban; the event vocabulary
 * is already here).
 *
 * `eventKey()` returns the board event-key string (e.g.
 * `assignment.draft_approved`) — the SAME value as the audit verb, matching
 * the docs/10-BOARD-AUTOMATION.md §2 catalogue exactly.
 */
interface AssignmentEventContract
{
    public function assignment(): CampaignAssignment;

    public function eventKey(): string;

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array;

    public function triggeredByUserId(): ?int;
}
