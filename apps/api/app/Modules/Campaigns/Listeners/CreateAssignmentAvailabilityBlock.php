<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Listeners;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Campaigns\Events\AssignmentTransitioned;
use App\Modules\Creators\Enums\BlockType;
use App\Modules\Creators\Enums\Kind;
use App\Modules\Creators\Models\CreatorAvailabilityBlock;
use Illuminate\Support\Facades\Log;

/**
 * The FIRST consumer of {@see AssignmentTransitioned} (Sprint 8 Chunk 2, D-11).
 *
 * When a creator ACCEPTS an assignment, auto-create a HARD availability block
 * over the campaign's posting window so the creator reads as busy to OTHER
 * agencies' conflict checks (the Sprint-3 hook finally fires). This is a REAL
 * listener — deliberately NOT inline in the accept endpoint — establishing the
 * event-consumer pattern the board sprint follows.
 *
 * The block:
 *   - {@see BlockType::Hard}        — shows as a genuine conflict to others;
 *   - {@see Kind::AssignmentAuto}   — the system-reserved kind (creators can't
 *                                     create it; D-13 lets the calendar label
 *                                     it "from campaign X" via this + the link);
 *   - linked via `assignment_id`    — the FK added in Chunk 2 (D-12), so
 *                                     deleting the assignment SET-NULLs this;
 *   - spanning the posting window   — `posting_window_*`, falling back to the
 *                                     campaign run dates (`starts_at`/`ends_at`)
 *                                     when the posting window is null.
 *
 * Fires inside the state-machine's commit transaction (the event is dispatched
 * within {@see CampaignAssignmentStateMachine::commit()}'s DB::transaction), so
 * the status flip and the block creation are atomic.
 */
final class CreateAssignmentAvailabilityBlock
{
    public function handle(AssignmentTransitioned $event): void
    {
        if ($event->action !== AuditAction::AssignmentAccepted) {
            return;
        }

        $assignment = $event->assignment;
        $campaign = $assignment->campaign;

        if ($campaign === null) {
            return;
        }

        $startsAt = $campaign->posting_window_starts_at ?? $campaign->starts_at;
        $endsAt = $campaign->posting_window_ends_at ?? $campaign->ends_at;

        // Edge (flagged at plan-pause): if neither the posting window nor the
        // campaign run dates are set, the conflict has no span — there is
        // nothing to block. Skip rather than persist a zero-width / null block.
        if ($startsAt === null || $endsAt === null) {
            Log::warning('Skipped assignment auto-block: campaign has no dateable window', [
                'assignment_id' => $assignment->id,
                'campaign_id' => $campaign->id,
            ]);

            return;
        }

        CreatorAvailabilityBlock::create([
            'creator_id' => $assignment->creator_id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'is_all_day' => false,
            'kind' => Kind::AssignmentAuto,
            'block_type' => BlockType::Hard,
            'reason' => null,
            'assignment_id' => $assignment->id,
        ]);
    }
}
