<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Campaigns\Services\CampaignAssignmentStateMachine;
use Illuminate\Console\Command;

/**
 * One-shot stuck-row remediation (toggle-off-flow chunk, D4).
 *
 * The toggle-off flow (D2) auto-advances `accepted → contracted` at INVITE-accept
 * time for campaigns that do NOT require a per-campaign contract, so a creator on
 * such a campaign never lands in the `accepted` dead-end. But rows that were
 * already `accepted` BEFORE this chunk shipped (or on a campaign toggled OFF
 * after acceptance, D5) are stranded there — the auto-advance only fires on new
 * accepts. This command drives those existing rows forward via a contract-less
 * advance (`contract($assignment, null)`), exactly as the accept path does.
 *
 * SCOPE (Q3): `accepted` status ONLY, and only campaigns with
 * `requires_per_campaign_contract = false`. `invited` rows advance themselves
 * through the normal accept path; `requires=true` campaigns keep their contract
 * step (D7). It drives the machine directly, so it is NEVER blocked by the
 * `per_campaign_contract_enabled` flag (Q2 asymmetry — the flag gates the
 * contract FEATURE, irrelevant to a contract-less advance).
 *
 * IDEMPOTENT: it advances rows OUT of `accepted`, so a second run finds none.
 * AUDIT-DISTINGUISHABLE (D6): the transition carries `auto_advanced: true,
 * source: backfill`, so a future reader can tell it apart from the accept-chained
 * auto-advance (`auto_advanced: true`, no source) and the agency's manual
 * proceed-without-contract (no `auto_advanced` at all).
 *
 * Documented POST-DEPLOY step (joins the AH-026 recompute in the pending-deploy
 * list). `--dry-run` reports what would advance without writing (run it first).
 */
final class AdvanceContractlessAcceptedAssignments extends Command
{
    protected $signature = 'campaigns:advance-contractless-accepted
        {--dry-run : Report how many assignments would advance without writing}';

    protected $description = 'Advance stuck accepted assignments on requires=false campaigns to contracted with no contract (idempotent).';

    public function handle(CampaignAssignmentStateMachine $machine): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $processed = 0;
        $advanced = 0;

        CampaignAssignment::query()
            ->withoutGlobalScope(BelongsToAgencyScope::class)
            ->where('status', AssignmentStatus::Accepted->value)
            ->whereHas('campaign', function ($query): void {
                $query->withoutGlobalScope(BelongsToAgencyScope::class)
                    ->where('requires_per_campaign_contract', false);
            })
            ->orderBy('id')
            ->chunkById(200, function ($assignments) use ($machine, $dryRun, &$processed, &$advanced): void {
                foreach ($assignments as $assignment) {
                    $processed++;

                    if ($dryRun) {
                        $advanced++;

                        continue;
                    }

                    $machine->contract($assignment, null, null, [
                        'auto_advanced' => true,
                        'source' => 'backfill',
                    ]);

                    $advanced++;
                }
            });

        $this->info(sprintf(
            '%s %d accepted assignment(s) on requires=false campaigns; %d %s.',
            $dryRun ? 'Checked' : 'Processed',
            $processed,
            $advanced,
            $dryRun ? 'would advance' : 'advanced to contracted',
        ));

        return self::SUCCESS;
    }
}
