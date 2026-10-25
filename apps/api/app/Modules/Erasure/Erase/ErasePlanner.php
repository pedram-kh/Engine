<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Erase;

use App\Modules\Erasure\Claim\ClaimMode;
use App\Modules\Erasure\Claim\ErasureClaimer;
use App\Modules\Erasure\Eligibility\ErasureEligibility;
use App\Modules\Erasure\Execution\DatabaseAuditScrub;
use App\Modules\Erasure\Models\ErasureLedger;
use App\Modules\Erasure\Queue\ErasureRunStatus;
use App\Modules\Erasure\Run\ErasureRun;
use App\Modules\Erasure\Selection\Selections;
use App\Modules\Erasure\Subject\SubjectNotErasable;
use App\Modules\Identity\Models\User;

/**
 * What the admin reads before the Erase click (AH-104c C3): the dry-run as numbers, the gate's
 * verdict as codes, and the digest the click must send back. READ-ONLY: it calls the same
 * `ErasureRun::plan()` as `erasure:run` without `--execute`, so the page and the command cannot
 * disagree, and it writes nothing (a spec hashes every table around it).
 *
 * It lives in the Erasure module because the admin controller may not import the run (an
 * architecture spec); the controller gets this view and nothing that can erase.
 *
 * Nothing here is personal data: table names, row counts, object counts, codes. The blockers are
 * the coarse codes of {@see ErasureEligibility}, never the guards' sentences.
 */
final class ErasePlanner
{
    public const AUDIT_SCRUB_UNAVAILABLE = 'audit_scrub_unavailable';

    public function __construct(
        private readonly ErasureRun $run,
        private readonly ErasureClaimer $claimer,
        private readonly ErasureEligibility $eligibility,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws SubjectNotErasable the account is not an erasable creator (erased, or no profile)
     */
    public function view(User $user): array
    {
        $plan = $this->run->plan($user);

        $claimed = $user->isErasureClaimed();
        // A claimed account is not asked again (it passed the gate at the claim); a retry re-plans.
        $gate = $claimed ? [] : $this->claimer->gateCodes($user, ClaimMode::Normal);
        $blockers = $this->eligibility->blockers($user);
        if (! $plan->auditScrubAvailable) {
            $blockers[] = self::AUDIT_SCRUB_UNAVAILABLE;
        }

        $assignments = 0;
        foreach ($plan->report->lines as $line) {
            if ($line['id'] === Selections::CANCEL_ASSIGNMENTS) {
                $assignments = $line['count'];
            }
        }

        $ledger = $claimed ? ErasureLedger::query()->where('user_ulid', $user->ulid)->orderByDesc('id')->first() : null;

        return [
            'id' => $user->ulid,
            'type' => 'erasure_plans',
            'attributes' => [
                'user_ulid' => $user->ulid,
                'claimed' => $claimed,
                'run' => ErasureRunStatus::for($user, $ledger)?->toArray(),
                'gate' => $gate,
                'blockers' => $blockers,
                'ready' => $gate === [] && $blockers === [],
                'database' => $plan->report->lines,
                'files' => $plan->files->summary(),
                'cost' => $plan->report->cost(DatabaseAuditScrub::CHUNK),
                'assignments_to_cancel' => $assignments,
                'vendor_calls' => 0,
                'audit_scrub_available' => $plan->auditScrubAvailable,
                'digest' => $plan->digest(),
            ],
        ];
    }
}
