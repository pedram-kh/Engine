<?php

declare(strict_types=1);

namespace App\Modules\Boards\Enums;

/**
 * What triggered a board card movement (Sprint 12 Chunk 1, Q1/D-9). Per
 * docs/03-DATA-MODEL.md §10 (`board_card_movements.triggered_by` — "`event` or
 * `user`").
 *
 * `Event` = an automation moved the card off an AssignmentTransitioned event
 * (no audit-log row — system-driven). `User` = a human dragged the card (the
 * manual move writes BOTH an audit_logs row and this movement row, D-9).
 *
 * Q1 reconciliation: D-9's prose said "automation" for the system value, but
 * §10's schema note, the §5.2 listener sketch, and §13 all say "event" — the
 * §10 column authority (D-1) wins, so the system value is `event`.
 *
 * Stored as varchar(16) on `board_card_movements.triggered_by`.
 */
enum MovementTrigger: string
{
    case Event = 'event';
    case User = 'user';
}
