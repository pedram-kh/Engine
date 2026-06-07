<?php

declare(strict_types=1);

namespace App\Modules\Boards\Services;

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Campaigns\Models\CampaignAssignment;
use Illuminate\Support\Carbon;

/**
 * The daily overdue sweep (Sprint 12 Chunk 3, D-3/D-4/D-6). Per
 * docs/10-BOARD-AUTOMATION.md §2 (the two time-triggered P1 event keys) + §15
 * (the time-trigger framing).
 *
 * ⚠ Tenancy (D-6 — the MessageDigestService lesson). This runs from the
 * scheduled command in a console with NO ambient agency context, so
 * {@see BelongsToAgencyScope} is a no-op. The sweep is therefore a DELIBERATE
 * global query across ALL agencies ({@see BelongsToAgencyScope} bypassed) — a
 * global "deadline passed" sweep is correct here. Per-card isolation is
 * structural, not query-scoped: each match is handed to
 * {@see BoardAutomationService::processEvent()}, which self-resolves the card's
 * board (hence agency) from the assignment — so agency A's automation config
 * cannot fire on agency B's card. The obligation is the cross-agency ABSENCE
 * test (mirror MessageDigestTest).
 *
 * ⚠ One-shot (D-4). The overdue fires AT MOST ONCE per assignment per overdue
 * type. The query gates on `*_overdue_flagged_at IS NULL`, and the marker is
 * stamped BEFORE the event is dispatched — so even if a human drags the card
 * out of the overdue column while still overdue, the next daily scan skips it
 * (the engine's already-in-target no-op alone would re-fire and fabricate a new
 * movement row daily). The marker is stamped even when processEvent no-ops
 * (no board / no mapped automation / terminal assignment with a stale deadline):
 * "fired once, did nothing" is the desired bounded behavior (D-1 — the verbs are
 * the vocabulary; an unmapped key is a no-op), not a bug. P1 posture: the marker
 * is permanent — it does NOT reset if the deadline is later cleared/extended
 * (the reset-on-un-overdue refinement is logged to tech-debt).
 *
 * ⚠ Direct processEvent (D-3). The sweep calls processEvent DIRECTLY — it does
 * NOT dispatch a synthetic AssignmentTransitioned. An overdue is not a status
 * change (it has no sane from/to), and a synthetic transition would mis-fire
 * every OTHER AssignmentTransitioned consumer (notifications, system-message,
 * thread-create, card-create). The listener is just a thin adapter onto the same
 * processEvent call, so the engine is reused unchanged.
 */
final class OverdueScanService
{
    public function __construct(private readonly BoardAutomationService $automations) {}

    /**
     * Fire both overdue events across all agencies. Returns the per-type counts
     * of events fired this run.
     *
     * @return array{posting: int, draft: int}
     */
    public function scan(): array
    {
        $now = Carbon::now();

        return [
            'posting' => $this->fireOverdue(
                'posting_due_at',
                'posting_overdue_flagged_at',
                AuditAction::AssignmentPostingOverdue,
                $now,
            ),
            'draft' => $this->fireOverdue(
                'draft_due_at',
                'draft_overdue_flagged_at',
                AuditAction::AssignmentDraftOverdue,
                $now,
            ),
        ];
    }

    /**
     * The single overdue-type sweep. Deadline passed (`$dueColumn < now()`) AND
     * not yet flagged (`$flagColumn IS NULL`) AND a deadline IS set (`$dueColumn
     * IS NOT NULL` — skip nulls). Stamps the marker, then fires the event.
     */
    private function fireOverdue(string $dueColumn, string $flagColumn, AuditAction $action, Carbon $now): int
    {
        $assignments = CampaignAssignment::query()
            ->withoutGlobalScope(BelongsToAgencyScope::class)
            ->whereNotNull($dueColumn)
            ->whereNull($flagColumn)
            ->where($dueColumn, '<', $now)
            ->get();

        $fired = 0;

        foreach ($assignments as $assignment) {
            // Stamp the marker BEFORE firing — the one-shot gate (D-4). Set even
            // if processEvent below no-ops, so a stale deadline fires once and
            // never again.
            $assignment->forceFill([$flagColumn => $now])->save();

            $this->automations->processEvent(
                assignmentId: $assignment->id,
                eventKey: $action->value,
                metadata: [
                    'overdue_type' => $action->value,
                    'due_at' => $assignment->getAttribute($dueColumn)?->toIso8601String(),
                ],
                triggeredByUserId: null,
            );

            $fired++;
        }

        return $fired;
    }
}
