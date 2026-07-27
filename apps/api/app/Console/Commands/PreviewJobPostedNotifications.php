<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Services\JobPostedFanOutService;
use Illuminate\Console\Command;

/**
 * The operator furniture for the job-posted fan-out (AH-056, D6) — the
 * SendIncompleteCreatorNudges shape, minus the schedule entry.
 *
 * Two jobs, and only two:
 *
 *  1. `--dry-run` — flag-AGNOSTIC, mutation-free. It prints what a send would
 *     do BEFORE the Pennant flag is ever flipped, which is the whole point:
 *     this is the arc's first mail fan-out to a live roster (~279 creators),
 *     and the first-enable ritual in feature-flags.md is "dry-run first, read
 *     the number, then flip".
 *  2. Draining a capped roster. The flip's queued job sends at most
 *     {@see JobPostedFanOutService::DEFAULT_LIMIT} — re-running this command
 *     without `--dry-run` sends the next batch, and the once-only stamp means
 *     nobody hears twice no matter how often it runs.
 *
 * The flag check lives in the SERVICE, not here (the AH-048 split), so the
 * console path and the queued path cannot disagree about whether the fan-out
 * is on. A run with the flag OFF prints a "disabled" line and exits 0.
 *
 * NOT registered in bootstrap/app.php's schedule, deliberately: the production
 * scheduler is unverified (the standing blocker in RESUMPTION-TEMPLATE.md), so
 * a scheduled drain would be a promise this codebase cannot keep. An operator
 * invokes it.
 */
final class PreviewJobPostedNotifications extends Command
{
    protected $signature = 'campaigns:preview-job-notifications
        {campaign : The campaign ULID}
        {--dry-run : Count who would be notified, without sending or stamping}
        {--limit= : Max notifications this run, oldest-roster-first (default '.JobPostedFanOutService::DEFAULT_LIMIT.')}';

    protected $description = 'Preview or drain the job-posted notification fan-out for one listed campaign.';

    public function handle(JobPostedFanOutService $service): int
    {
        $limit = $this->resolveLimit();
        if ($limit === null) {
            $this->error('--limit must be a positive integer.');

            return self::FAILURE;
        }

        $campaign = $this->resolveCampaign();
        if (! $campaign instanceof Campaign) {
            $this->error('No campaign found for ULID '.(string) $this->argument('campaign').'.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $report = $service->preview($campaign, $limit);

            $this->info(sprintf(
                '[dry-run] "%s": would notify %d creator(s), %d would remain after this run (cap %d). No changes made.',
                $campaign->name,
                $report->notified,
                $report->remaining,
                $limit,
            ));

            return self::SUCCESS;
        }

        $report = $service->send($campaign, $limit);

        if (! $report->enabled) {
            $this->info('job_posted_notifications_enabled is OFF — nothing sent. Re-run with --dry-run to see the volume a flip would release.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '"%s": notified %d creator(s) (cap %d).',
            $campaign->name,
            $report->notified,
            $limit,
        ));

        if ($report->remaining > 0) {
            // Said out loud, because a silently truncated fan-out is how a
            // roster's tail never hears about a job at all.
            $this->warn(sprintf(
                '%d creator(s) still un-notified — re-run to send the next batch.',
                $report->remaining,
            ));
        }

        return self::SUCCESS;
    }

    /**
     * Resolve the ULID argument across every agency: the console has no tenancy
     * context to scope by, and an operator draining a fan-out is acting for the
     * platform, not for one agency.
     */
    private function resolveCampaign(): ?Campaign
    {
        $ulid = $this->argument('campaign');

        if (! is_string($ulid) || $ulid === '') {
            return null;
        }

        return Campaign::query()
            ->withoutGlobalScope(BelongsToAgencyScope::class)
            ->where('ulid', $ulid)
            ->first();
    }

    /**
     * `--limit` as a positive int, or the service default when absent. An
     * invalid value fails loudly rather than quietly running uncapped or
     * capped at zero — the nudge command's precedent.
     */
    private function resolveLimit(): ?int
    {
        $raw = $this->option('limit');

        if ($raw === null) {
            return JobPostedFanOutService::DEFAULT_LIMIT;
        }

        if (! is_string($raw) || ! ctype_digit($raw) || (int) $raw < 1) {
            return null;
        }

        return (int) $raw;
    }
}
