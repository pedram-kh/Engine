<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Cancellation;

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Core\Tenancy\TenancyContext;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Campaigns\Services\CampaignAssignmentStateMachine;
use App\Modules\Erasure\Selection\Selections;
use App\Modules\Erasure\Subject\SubjectSet;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * The AH-104b-del rider (Q4 c): an erased creator's open assignments are cancelled, so
 * no agency is left waiting on a person who no longer exists.
 *
 * It lives here and not in `Execution/` on purpose. The engine writes through the raw
 * query builder and an architecture spec forbids a model there; this step is the
 * opposite, it must go through {@see CampaignAssignmentStateMachine} (the audit row, the
 * transition event, the agency notice), which is model code. So the engine never runs
 * its stage (4h) and `ErasureRun` calls this inside the engine's transaction: a failure
 * rolls the whole erasure back, and the audit rows it writes are covered by the closing
 * audit pass that follows.
 *
 * Which rows: the same selection the dry-run counts (`campaign_assignments.cancel`), so
 * "the dry-run said N" and "N were cancelled" cannot drift apart. The actor is the
 * system (null), the reason a fixed code, never a sentence.
 */
final class CancelOpenAssignments
{
    public function __construct(
        private readonly CampaignAssignmentStateMachine $machine,
        private readonly TenancyContext $tenancy,
    ) {}

    /** @return int assignments cancelled */
    public function run(SubjectSet $subject, ?ConnectionInterface $db = null): int
    {
        $db ??= DB::connection();
        $ids = Selections::find(Selections::CANCEL_ASSIGNMENTS)->builder($subject, $db)->orderBy('id')->pluck('id');

        // No agency context: the rows belong to many agencies, and the scope is bypassed
        // by name below as well, so the bypass is visible where it happens.
        return (int) $this->tenancy->runAs(null, function () use ($ids): int {
            $cancelled = 0;
            foreach ($ids as $id) {
                $assignment = CampaignAssignment::query()
                    ->withoutGlobalScope(BelongsToAgencyScope::class)
                    ->lockForUpdate()
                    ->whereKey((int) $id)
                    ->first();

                // Re-read under the lock: it may have reached a terminal state since the selection.
                if ($assignment === null || $assignment->status->isTerminal()) {
                    continue;
                }

                $this->machine->cancel($assignment, CampaignAssignment::CANCELLED_REASON_CREATOR_ERASED, null);
                $cancelled++;
            }

            return $cancelled;
        });
    }
}
