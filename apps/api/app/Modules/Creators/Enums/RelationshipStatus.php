<?php

declare(strict_types=1);

namespace App\Modules\Creators\Enums;

/**
 * Status of an agency-creator relationship row.
 *
 *   - roster:   creator is on the agency's active roster.
 *   - external: creator engaged for a specific campaign without joining roster.
 *   - prospect: invited via bulk-invite (Sprint 3) but hasn't completed
 *               the wizard yet. Magic-link invitation columns on
 *               agency_creator_relations are active in this state.
 *
 * Stored as varchar(16) on agency_creator_relations.relationship_status.
 * See docs/03-DATA-MODEL.md §6.
 */
enum RelationshipStatus: string
{
    case Roster = 'roster';
    case External = 'external';
    case Prospect = 'prospect';
}
