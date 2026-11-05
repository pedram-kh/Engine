<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Services;

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Campaigns\Exceptions\AssignmentOfferEditException;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Identity\Models\User;
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
    public function __construct(
        private readonly AssignmentOfferLock $lock,
    ) {}

    /**
     * @throws AssignmentOfferEditException
     */
    public function edit(CampaignAssignment $assignment, int $newAmount, User $actor): CampaignAssignment
    {
        return DB::transaction(function () use ($assignment, $newAmount): CampaignAssignment {
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

            $locked->forceFill(['agreed_fee_minor_units' => $newAmount])->save();

            return $locked;
        });
    }
}
