<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Services;

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Campaigns\Exceptions\AssignmentOfferEditException;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Services\MessageService;
use App\Modules\Messaging\Services\MessageThreadService;
use Illuminate\Support\Facades\DB;

/**
 * The agency edits an assignment's offer amount (AH-118 item 2).
 *
 * Deliberately NOT a state-machine edge. `CampaignAssignmentStateMachine::commit`
 * always dispatches `AssignmentTransitioned`, which fans out to board
 * automation, the board card, the availability block, system messages,
 * notifications and verification dispatch. An edit changes no status, so none of
 * that may fire; this service writes the one column and nothing else.
 *
 * Concurrency: the row is read FOR UPDATE inside the transaction and the lock
 * rule is re-evaluated against that fresh row, never against the caller's
 * in-memory model (which may be stale by a contract attach or a creator
 * response). Contract attach takes the same row lock, so the two serialise.
 */
final class AssignmentOfferEditor
{
    /** The `messages.system_event_key` of the history line (an AuditAction value). */
    public const string SYSTEM_EVENT_KEY = 'assignment.offer_edited';

    public function __construct(
        private readonly AssignmentOfferLock $lock,
        private readonly AssignmentOfferEditedNotifier $notifier,
        private readonly MessageThreadService $threads,
        private readonly MessageService $messages,
    ) {}

    /**
     * @throws AssignmentOfferEditException
     */
    public function edit(CampaignAssignment $assignment, int $newAmount, User $actor): CampaignAssignment
    {
        $previous = 0;

        $updated = DB::transaction(function () use ($assignment, $newAmount, $actor, &$previous): CampaignAssignment {
            // The id comes from an already-authorised route binding, so the
            // tenancy scope is lifted only to make the locking read explicit.
            $locked = CampaignAssignment::query()
                ->withoutGlobalScope(BelongsToAgencyScope::class)
                ->lockForUpdate()
                ->findOrFail($assignment->id);

            $reason = $this->lock->evaluate($locked);
            if ($reason !== null) {
                throw AssignmentOfferEditException::locked($reason);
            }

            if ($locked->agreed_fee_minor_units === $newAmount) {
                throw AssignmentOfferEditException::unchanged();
            }

            $previous = (int) $locked->agreed_fee_minor_units;

            $locked->forceFill(['agreed_fee_minor_units' => $newAmount])->save();

            // Audit and the history line commit WITH the edit: a change that
            // is on the row but not in the record (or the thread) must not exist.
            Audit::log(
                action: AuditAction::AssignmentOfferEdited,
                actor: $actor,
                subject: $locked,
                metadata: [
                    'from' => $locked->status->value,
                    'previous_agreed_fee_minor_units' => $previous,
                    'agreed_fee_minor_units' => $newAmount,
                    'agreed_fee_currency' => $locked->agreed_fee_currency,
                ],
                agencyId: $locked->agency_id,
            );

            // The visible history line (both sides read the thread). Amounts
            // only: the sentence is rendered client-side from key + data.
            $this->messages->writeSystemMessage(
                $this->threads->forAssignment($locked),
                self::SYSTEM_EVENT_KEY,
                [
                    'previous_amount' => $previous,
                    'new_amount' => $newAmount,
                    'currency' => $locked->agreed_fee_currency,
                ],
            );

            return $locked;
        });

        // AFTER the commit, never inside it (see the notifier's note).
        $this->notifier->notify($updated->loadMissing(['creator.user', 'campaign']), $previous, $newAmount, $actor);

        return $updated;
    }
}
