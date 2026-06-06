<?php

declare(strict_types=1);

namespace App\Modules\Boards\Enums;

/**
 * The action a board automation performs when its event fires (Sprint 12 Chunk
 * 1, D-1). Per docs/03-DATA-MODEL.md §10 (`board_automations.action_type`).
 *
 * `MoveToColumn` moves the card to `target_column_id`; `None` is the inert
 * action (the automation exists but does nothing) — the listener honors both
 * (D-1). Stored as varchar(16) on `board_automations.action_type`.
 */
enum BoardAutomationActionType: string
{
    case MoveToColumn = 'move_to_column';
    case None = 'none';
}
