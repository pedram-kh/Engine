<?php

declare(strict_types=1);

namespace App\Modules\Boards\Services;

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Campaigns\Models\CampaignAssignment;
use Illuminate\Database\Eloquent\Builder;
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
                // AH-069 (D8) — never flag an assignment as overdue-to-post on a
                // campaign that does not ask its creators to post. The writer
                // side (CampaignInvitationService) stops stamping the deadline
                // at all for those campaigns, so this catches exactly one case:
                // an assignment invited while the toggle was ON, on a campaign
                // that has since been turned OFF. Its stale deadline would
                // otherwise pass, flag, and dispatch an overdue for a step that
                // no longer exists — permanently, since the marker never resets.
                //
                // Reading through to the campaign rather than excluding the
                // `completed_on_approval` status is deliberate: the status
                // exclusion would only cover assignments that had already been
                // approved, and would leave an OFF campaign's `contracted`
                // assignment flagged for a post it will never be asked to make.
                constrain: static fn (Builder $query): Builder => $query->whereHas(
                    'campaign',
                    static fn (Builder $campaign): Builder => $campaign
                        ->withoutGlobalScope(BelongsToAgencyScope::class)
                        ->where('creator_posts_content', true),
                ),
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
     *
     * `$constrain` narrows the population for one overdue type only; the posting
     * sweep uses it (AH-069, D8). An excluded assignment is not merely skipped —
     * its marker is NOT stamped either, so if the campaign's posting toggle is
     * turned back on, the deadline is live again and the sweep can still flag it.
     *
     * @param  (callable(Builder<CampaignAssignment>): Builder<CampaignAssignment>)|null  $constrain
     */
    private function fireOverdue(string $dueColumn, string $flagColumn, AuditAction $action, Carbon $now, ?callable $constrain = null): int
    {
        $query = CampaignAssignment::query()
            ->withoutGlobalScope(BelongsToAgencyScope::class)
            ->whereNotNull($dueColumn)
            ->whereNull($flagColumn)
            ->where($dueColumn, '<', $now);

        if ($constrain !== null) {
            $query = $constrain($query);
        }

        $assignments = $query->get();

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
