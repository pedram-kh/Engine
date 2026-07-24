<?php

declare(strict_types=1);

namespace App\Modules\Creators\Enums;

/**
 * Status of an agency-creator relationship row.
 *
 *   - roster:           creator is on the agency's active roster (a real,
 *                       accepted working relationship).
 *   - external:         creator engaged for a specific campaign without
 *                       joining the roster.
 *   - prospect:         invited via bulk-invite (Sprint 3) but hasn't
 *                       completed the wizard yet. Magic-link invitation
 *                       columns on agency_creator_relations are active in
 *                       this state (a token + expiry exist).
 *   - pending_request:  the agency sent a discovery connection request
 *                       (Sprint 6.6b, D-1) and the creator has NOT yet
 *                       accepted. Distinguished from `prospect` by carrying
 *                       NO magic-link token/expiry — the creator already has
 *                       an account; they accept/decline in-app, not via a
 *                       magic link. Excluded from the default roster index
 *                       (D-6) but filterable by an explicit chip.
 *   - declined:         the creator declined a pending_request (Sprint 6.6b,
 *                       D-1). Terminal, but the row is RETAINED so the
 *                       unique (agency_id, creator_id) pair stays occupied and
 *                       the agency can deliberately re-request (declined →
 *                       pending_request, D-4). Excluded from the default
 *                       roster index (D-6) but filterable by an explicit chip.
 *   - ended:            a previously-`roster` relationship the platform admin
 *                       SEVERED (AH-051, D-3) — the platform's first relation
 *                       termination. Like `declined`, it is a retained terminal
 *                       state that occupies the unique pair and is
 *                       RE-REQUESTABLE (ended → pending_request, via the agency
 *                       send-request path or admin Door 1). Never messageable,
 *                       never contact-visible, and excluded from the default
 *                       roster index (joins DEFAULT_EXCLUDED_STATUSES) —
 *                       filterable by an explicit chip. Reachable ONLY from
 *                       `roster` via admin disconnect (D-6).
 *
 * Stored as varchar(16) on agency_creator_relations.relationship_status
 * (a plain varchar with NO DB CHECK constraint — adding a case needs no
 * migration; the enum + RelationshipStatusEnumTest catalogue are the
 * documentation). See docs/03-DATA-MODEL.md §6.
 */
enum RelationshipStatus: string
{
    case Roster = 'roster';
    case External = 'external';
    case Prospect = 'prospect';
    case PendingRequest = 'pending_request';
    case Declined = 'declined';
    case Ended = 'ended';
}
