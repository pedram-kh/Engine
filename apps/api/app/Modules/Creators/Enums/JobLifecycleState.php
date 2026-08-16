<?php

declare(strict_types=1);

namespace App\Modules\Creators\Enums;

use App\Modules\Campaigns\Enums\AssignmentStatus;
use Tests\Feature\Modules\Creators\JobLifecycleStateTest;

/**
 * The COARSE, read-only lifecycle state a creator's own job surfaces reflect
 * once an application has become an engagement (AH-059, D5).
 *
 * Three states, derived at read time from {@see AssignmentStatus} by
 * {@see fromAssignmentStatus()}. **There is no column, no event and no sync** —
 * nothing persists this, and nothing may. It exists so a creator looking at the
 * job they applied to is told what is happening to it in the vocabulary of the
 * job board, not in the vocabulary of the 16-state assignment machine (which is
 * an agency-side instrument and stays one).
 *
 * It also settles D1's contradiction structurally rather than by special case:
 * when a pair has an assignment, the surfaces render THIS instead of the
 * application's own answer, so a rejected application can never render
 * "Not selected" beside a live invitation for the same campaign. The
 * consequence, accepted deliberately at plan-pause (Q2): a pair that was ever
 * invited never reads "Not selected" again — the agency's last act on that pair
 * was an invitation, not a refusal, and that is the honest story.
 *
 * ⚠ **`AssignmentStatus::isTerminal()` is NOT the `Ended` predicate, and must
 * never be substituted for one.** It returns TRUE for
 * {@see AssignmentStatus::PaymentReleased} — a *successful* terminus — so a
 * fully-paid engagement would render as "Ended". The nearest-looking helper is
 * the wrong helper here; the mapping below is written from the enum itself.
 *
 * ⚠ **The mapping is an exhaustive `match` with NO `default` arm, on purpose.**
 * A 17th `AssignmentStatus` case must break the BUILD (PHPStan level max reports
 * the match as non-exhaustive) rather than silently fall through to a label in a
 * creator's browser. {@see JobLifecycleStateTest}
 * carries the same guarantee at the test layer — both are required, because a
 * test alone is not a build break.
 */
enum JobLifecycleState: string
{
    /**
     * The engagement is live and moving: from the invitation itself through the
     * offer, the contract, production and the whole draft/review cycle.
     * `Approved` belongs here and not in {@see self::Completed} — the draft is
     * approved but nothing is posted yet, and telling a creator "Completed"
     * before their content is live would over-promise.
     */
    case InProgress = 'in_progress';

    /**
     * The work is delivered. Posted, verified either way, and the payment states
     * that follow it: with only three states, "paid" has nowhere else to live,
     * and money in motion is unambiguously past delivery.
     */
    case Completed = 'completed';

    /**
     * The engagement ended without delivery — the creator declined, the agency
     * rejected the draft, or the assignment was cancelled. Note this set is
     * deliberately NARROWER than `isTerminal()` (see the class docblock).
     */
    case Ended = 'ended';

    /**
     * The single source for the family mapping. Spec-pinned by the catalogue
     * test; exhaustive by construction (no `default`).
     */
    public static function fromAssignmentStatus(AssignmentStatus $status): self
    {
        return match ($status) {
            AssignmentStatus::Invited,
            AssignmentStatus::Countered,
            AssignmentStatus::Accepted,
            AssignmentStatus::Contracted,
            AssignmentStatus::Producing,
            AssignmentStatus::DraftSubmitted,
            AssignmentStatus::RevisionRequested,
            AssignmentStatus::Approved => self::InProgress,

            // `CompletedOnApproval` (AH-069 D4) is `Completed` and not
            // `InProgress`, which is where its source state `Approved` sits one
            // line above. The distinction is the whole point of the chunk: on a
            // campaign that hands off at approval there is no posting step left,
            // so "Completed" is the truth rather than the over-promise the
            // `Approved` docblock warns about. It is likewise not `Ended` —
            // `Ended` means "ended WITHOUT delivery", and this delivered.
            AssignmentStatus::CompletedOnApproval,
            AssignmentStatus::Posted,
            AssignmentStatus::LiveVerified,
            AssignmentStatus::ManuallyVerified,
            AssignmentStatus::PaymentHeld,
            AssignmentStatus::PaymentReleased => self::Completed,

            AssignmentStatus::Declined,
            AssignmentStatus::Rejected,
            AssignmentStatus::Cancelled => self::Ended,
        };
    }

    /**
     * Convenience for the resources, which read a raw `status` string off a
     * correlated subquery rather than a hydrated model. Returns `null` for a
     * missing or unrecognised value — a subquery that found no assignment, not a
     * mapping gap. An unrecognised NON-empty value cannot occur while the column
     * is written exclusively by the state machine, so this is a guard on the
     * absent case rather than a silent fallback for a new enum member: a new
     * member reaches {@see fromAssignmentStatus()} and breaks the build there.
     */
    public static function tryFromAssignmentStatusValue(mixed $status): ?self
    {
        if (! is_string($status)) {
            return null;
        }

        $assignmentStatus = AssignmentStatus::tryFrom($status);

        return $assignmentStatus instanceof AssignmentStatus
            ? self::fromAssignmentStatus($assignmentStatus)
            : null;
    }
}
