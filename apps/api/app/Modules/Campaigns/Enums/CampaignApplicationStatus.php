<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Enums;

use App\Modules\Campaigns\Models\CampaignApplication;
use App\Modules\Creators\Enums\ApplicationStatus;

/**
 * The status of a {@see CampaignApplication} (Jobs Board chunk 3, AH-056, D1).
 * Stored as varchar(32) on `campaign_applications.status`.
 *
 * ⚠ Deliberately NOT named `ApplicationStatus`. That name is already taken by
 * {@see ApplicationStatus} — the creator's
 * ONBOARDING status (`incomplete | pending | approved | rejected`) — which
 * shares three of these four literal values and is read three lines away from
 * this one inside the jobs-board visibility predicate (the "approved caller"
 * leg). Two same-named enums with overlapping values in one import graph is an
 * ambiguity nobody should have to notice; the longer name is the cheap fix
 * (kickoff C4).
 *
 * The lifecycle is deliberately tiny — D1's accepted cost for keeping
 * applications out of the assignment state machine:
 *
 *   pending → accepted (terminal)
 *   pending → rejected (terminal)
 *
 * No edges out of either terminal, and no `withdrawn` v1. There is no state
 * machine class: with two edges from one source and no fee/offer/contract
 * fields to guard, {@see CampaignAssignmentStateMachine}'s apparatus would
 * cost more than it protects. The transition guard is the source-status check
 * at the (chunk 4) call site, and the audit trail is the
 * `campaign_application.*` verb family.
 *
 * `rejected` is RETAINED, never deleted — that retained row keeps the
 * (campaign, creator) unique pair occupied, which IS the "no re-apply after
 * rejection" rule (the `RelationshipStatus::Declined` precedent, where the row
 * is kept for exactly the same reason).
 */
enum CampaignApplicationStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';

    /**
     * Terminal statuses have no outgoing transition. Both outcomes are
     * terminal — the agency's decision ends the application's life, and
     * chunk 4's accept hands off to a `campaign_assignments` row rather than
     * continuing this lifecycle.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Accepted,
            self::Rejected => true,
            self::Pending => false,
        };
    }

    /**
     * The 409 error code a fresh apply gets when a row in this status already
     * exists for the (campaign, creator) pair (D5).
     *
     * Every status blocks — there is no re-apply from any of them — but the
     * REASON differs, and the creator-facing UI renders the two differently, so
     * they must not collapse into one code:
     *   - `pending` / `accepted` → "you have already applied"
     *   - `rejected`             → "you were not selected" (Apply stays dead)
     *
     * §5.4's non-fingerprinting rule does not apply: both facts are the
     * caller's own data about themselves, so there is nothing to leak by
     * distinguishing them.
     *
     * It lives on the enum, not at the call site, so the `match` is exhaustive
     * — a future status cannot be added without deciding what an apply against
     * it does.
     */
    public function reapplyBlockCode(): string
    {
        return match ($this) {
            self::Pending, self::Accepted => 'job.already_applied',
            self::Rejected => 'job.application_rejected',
        };
    }
}
