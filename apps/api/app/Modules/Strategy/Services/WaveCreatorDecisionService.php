<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Services;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Brands\Models\Brand;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Enums\CreatorDecisionStatus;
use App\Modules\Strategy\Models\WaveCreator;
use App\Modules\Strategy\Models\WaveCreatorDecision;
use App\Modules\Waves\Models\Wave;
use Illuminate\Support\Collection;

/**
 * The client's decision on a creator, recorded by an admin or manager on the client's behalf (PL-3.1, S4, P7). Append-only: a
 * new decision is a new row and the newest is current. Approved needs the client's name (a note is optional, no comment);
 * Suggest another needs the name and the client's comment. The audit row says that a decision was made, its status and two
 * flags; never the name, the note or the comment.
 *
 * After the row is written, in the same transaction and under the same wave lock, {@see WaveCreatorBoardRules::reconcile()}
 * moves the card when the table says so.
 */
final class WaveCreatorDecisionService
{
    public function __construct(private readonly WaveCreatorService $creators) {}

    /**
     * @param  array{status: string, client_name: string, note?: string|null, comment?: string|null}  $data  cleaned
     * @return array{decision: WaveCreatorDecision, slot: WaveCreator, board: array{moved: bool, card_not_moved: string|null}}
     */
    public function record(Brand $brand, Wave $wave, User $actor, string $slotUlid, array $data): array
    {
        StrategyGuard::assertBrandWritable($brand);

        $status = CreatorDecisionStatus::tryFrom($data['status']);
        if ($status === null) {
            throw StrategyRuleViolation::creatorDecisionInvalid('A decision is Approved or Suggest another.');
        }
        $name = $data['client_name'] ?? '';
        $note = $data['note'] ?? null;
        $comment = $data['comment'] ?? null;
        if ($name === '') {
            throw StrategyRuleViolation::creatorDecisionInvalid("The client's name is required.");
        }
        if ($status === CreatorDecisionStatus::Approved && $comment !== null) {
            throw StrategyRuleViolation::creatorDecisionInvalid('An approval carries a note, not a comment.');
        }
        if ($status === CreatorDecisionStatus::SuggestAnother && ($comment === null || $comment === '')) {
            throw StrategyRuleViolation::creatorDecisionInvalid("The client's comment is required when another creator is suggested.");
        }
        if ($status === CreatorDecisionStatus::SuggestAnother) {
            $note = null;
        }

        return WaveCreatorGuard::transaction($wave, function (Wave $locked) use ($actor, $slotUlid, $status, $name, $note, $comment): array {
            $slot = $this->creators->lockedSlot($locked, $slotUlid);

            $decision = WaveCreatorDecision::query()->create([
                'agency_id' => $locked->agency_id,
                'wave_id' => $locked->id,
                'wave_creator_id' => $slot->id,
                'status' => $status,
                'client_name' => $name,
                'note' => $note,
                'comment' => $comment,
                'recorded_by_user_id' => $actor->id,
                'recorded_at' => now(),
            ]);

            Audit::log(
                action: AuditAction::WaveCreatorDecided,
                actor: $actor,
                subject: $slot,
                // Literal keys. Never the client's name, note or comment (a third party's words about a person).
                metadata: ['wave' => $locked->ulid, 'market' => $slot->market->ulid, 'slot' => $slot->slot, 'creator_id' => $slot->creator_id, 'status' => $status->value, 'has_note' => $note !== null, 'has_comment' => $comment !== null],
                agencyId: $locked->agency_id,
            );

            $board = WaveCreatorBoardRules::reconcile($locked, $slot, WaveCreatorBoardRules::TRIGGER_DECISION, $actor);

            return ['decision' => $decision, 'slot' => $slot, 'board' => $board];
        });
    }

    /**
     * Every decision of a slot, newest first.
     *
     * @return Collection<int, WaveCreatorDecision>
     */
    public function history(Wave $wave, string $slotUlid): Collection
    {
        $slot = $this->creators->find($wave, $slotUlid);

        return WaveCreatorDecision::query()->where('wave_creator_id', $slot->id)->with('recordedBy:id,name')->orderByDesc('id')->get();
    }
}
