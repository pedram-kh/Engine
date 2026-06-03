<?php

declare(strict_types=1);

namespace App\Modules\Creators\Enums;

/**
 * The reason category for an availability block
 * (docs/03-DATA-MODEL.md §5, `:284`).
 *
 *   vacation            → time off.
 *   personal            → personal commitment.
 *   exclusive_contract  → contractually exclusive to another engagement.
 *   assignment_auto     → RESERVED: auto-created when a creator accepts a
 *                         campaign assignment (Sprint 8, forward-blocked on
 *                         campaign_assignments). NOT creator-settable — a
 *                         creator can never manually mint an "assignment
 *                         auto" block (D-a2). See {@see self::creatorSettable()}.
 *   other               → catch-all.
 *
 * Stored as varchar(16) on creator_availability_blocks.kind (required,
 * non-null column). Was a bare unvalidated string before Sprint 5 Chunk A
 * (inventory B4).
 */
enum Kind: string
{
    case Vacation = 'vacation';
    case Personal = 'personal';
    case ExclusiveContract = 'exclusive_contract';
    case AssignmentAuto = 'assignment_auto';
    case Other = 'other';

    /**
     * The kinds a creator may set via manual CRUD. Excludes
     * {@see self::AssignmentAuto}, which is system-reserved for the
     * deferred auto-block-on-acceptance flow (Sprint 8). Used by the
     * Store/UpdateAvailabilityBlockRequest `in:` rule so a creator-submitted
     * `assignment_auto` is rejected at validation (D-a2).
     *
     * @return list<string>
     */
    public static function creatorSettable(): array
    {
        return array_values(array_map(
            static fn (self $kind): string => $kind->value,
            array_filter(
                self::cases(),
                static fn (self $kind): bool => $kind !== self::AssignmentAuto,
            ),
        ));
    }
}
