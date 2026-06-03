<?php

declare(strict_types=1);

namespace App\Modules\Creators\Enums;

/**
 * Whether an availability block is a hard or soft constraint
 * (docs/03-DATA-MODEL.md §5, `:285`).
 *
 *   hard → the creator is excluded entirely for the window. Drives the
 *          Sprint 5 conflict-detection service: a hard block overlapping
 *          an invite range is a real conflict.
 *   soft → a warning-only preference. NOT a conflict — the agency may
 *          still invite, the UI just surfaces a soft heads-up (Sprint 8).
 *
 * Stored as varchar(8) on creator_availability_blocks.block_type. Was a
 * bare unvalidated string before Sprint 5 Chunk A (inventory B4).
 */
enum BlockType: string
{
    case Hard = 'hard';
    case Soft = 'soft';
}
