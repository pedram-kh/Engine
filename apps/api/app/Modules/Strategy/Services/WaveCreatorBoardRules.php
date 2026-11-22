<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Services;

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Boards\Models\BoardCard;
use App\Modules\Boards\Models\BoardColumn;
use App\Modules\Boards\Services\BoardCardMoveService;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Enums\CreatorDecisionStatus;
use App\Modules\Strategy\Models\WaveCreator;
use App\Modules\Strategy\Models\WaveCreatorDecision;
use App\Modules\Waves\Models\Wave;
use Illuminate\Support\Facades\DB;

/**
 * THE one place a creator step moves a wave board's card (PL-3.1 plan 1.6, P8; PL-6's hand-off to Filming joins here as one
 * more row of the same table). Called with the WAVE LOCK held, from the decision service, from Remove and Replace, and from the
 * accept listener (after the accept has committed). Whatever it needs it reads UNDER the lock from the database, so whichever
 * of "the client approved" and "the creator accepted" commits second sees the first and moves the card.
 *
 * It never writes `board_cards` itself: it calls {@see BoardCardMoveService::moveByRule()}. Events, not a schedule: a card the
 * agency moved by hand stays where they put it until the next event of this table.
 */
final class WaveCreatorBoardRules
{
    public const TRIGGER_DECISION = 'decision';

    public const TRIGGER_ACCEPT = 'accept';

    public const TRIGGER_REMOVE = 'remove';

    public const CARD_NOT_MOVED_BEYOND_SELECTED = 'beyond_selected';

    /**
     * Rows 2 to 14 of the plan's table. Returns what the caller should tell the person.
     *
     * @return array{moved: bool, card_not_moved: string|null}
     */
    public static function reconcile(Wave $locked, WaveCreator $slot, string $trigger, ?User $actor = null): array
    {
        $none = ['moved' => false, 'card_not_moved' => null];
        $found = self::cardOf($locked, $slot);
        if ($found === null) {
            return $none;
        }
        [$assignment, $card] = $found;
        $stage = $card->column?->stage_key;

        $latest = WaveCreatorDecision::query()->where('wave_creator_id', $slot->id)->orderByDesc('id')->first(['id', 'status']);
        if ($latest === null) {
            return $none;
        }

        if ($latest->status === CreatorDecisionStatus::Approved) {
            // Rows 3 and 7: approved AND accepted, still in Invited. Every other column is left alone (rows 4 to 6, 9).
            if ($stage === 'invited' && $assignment->status->countsAsSelected()) {
                return self::move($locked, $slot, $assignment, $card, 'selected', 'wave_creator.selected', $trigger, $actor);
            }

            return $none;
        }

        // Suggest another changes the card only on a DECISION (row 8: an accept over it does nothing).
        if ($trigger !== self::TRIGGER_DECISION) {
            return $none;
        }
        if ($stage === 'selected') {
            return self::move($locked, $slot, $assignment, $card, 'invited', 'wave_creator.reverted', $trigger, $actor);
        }
        if ($stage !== null && in_array($stage, ['filming', 'submitted', 'in_edits', 'client_review', 'approved'], true)) {
            return ['moved' => false, 'card_not_moved' => self::CARD_NOT_MOVED_BEYOND_SELECTED];
        }

        return $none;
    }

    /** Rows 15 and 18 (before the slot is deleted): Selected goes back to Invited, Filming and later REFUSES. */
    public static function release(Wave $locked, WaveCreator $slot, ?User $actor): void
    {
        $found = self::cardOf($locked, $slot);
        if ($found === null) {
            return;
        }
        [$assignment, $card] = $found;
        $stage = $card->column?->stage_key;

        if ($stage === 'selected') {
            self::move($locked, $slot, $assignment, $card, 'invited', 'wave_creator.reverted', self::TRIGGER_REMOVE, $actor);
        }
    }

    /** Row 17: refused BEFORE anything is written. */
    public static function assertRemovable(Wave $locked, WaveCreator $slot): void
    {
        $found = self::cardOf($locked, $slot);
        $stage = $found === null ? null : $found[1]->column?->stage_key;
        if ($stage !== null && in_array($stage, ['filming', 'submitted', 'in_edits', 'client_review', 'approved'], true)) {
            throw StrategyRuleViolation::creatorRemoveBlockedInWork();
        }
    }

    /**
     * @return array{0: CampaignAssignment, 1: BoardCard}|null
     */
    private static function cardOf(Wave $locked, WaveCreator $slot): ?array
    {
        if ($locked->campaign_id === null) {
            return null;
        }
        $assignment = CampaignAssignment::query()->withoutGlobalScope(BelongsToAgencyScope::class)
            ->where('agency_id', $locked->agency_id)->where('campaign_id', $locked->campaign_id)
            ->where('creator_id', $slot->creator_id)->orderByDesc('id')->first();
        if ($assignment === null) {
            return null;
        }
        $card = BoardCard::query()->withoutGlobalScope(BelongsToAgencyScope::class)->with('column')
            ->where('assignment_id', $assignment->id)->first();
        if ($card === null) {
            return null;
        }
        $card->setRelation('assignment', $assignment);

        return [$assignment, $card];
    }

    /**
     * @return array{moved: bool, card_not_moved: string|null}
     */
    private static function move(Wave $locked, WaveCreator $slot, CampaignAssignment $assignment, BoardCard $card, string $stageKey, string $eventKey, string $trigger, ?User $actor): array
    {
        $target = BoardColumn::query()->withoutGlobalScope(BelongsToAgencyScope::class)
            ->where('board_id', $card->board_id)->where('stage_key', $stageKey)->first();
        if ($target === null) {
            return ['moved' => false, 'card_not_moved' => null];
        }

        $from = $card->column_id;
        app(BoardCardMoveService::class)->moveByRule($card, $target, $eventKey, $actor?->id);

        if ($from !== $target->id) {
            Audit::log(
                action: AuditAction::WaveCreatorCardMoved,
                actor: $actor,
                subject: $card,
                // Literal keys: ids, the slot number, and the trigger. No name, no text.
                metadata: ['wave' => $locked->ulid, 'market' => DB::table('wave_markets')->where('id', $slot->market_id)->value('ulid'), 'slot' => $slot->slot, 'assignment_id' => $assignment->id, 'from_column_id' => $from, 'to_column_id' => $target->id, 'trigger' => $trigger],
                agencyId: $locked->agency_id,
            );
        }

        return ['moved' => true, 'card_not_moved' => null];
    }
}
